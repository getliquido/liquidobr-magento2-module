<?php

namespace Liquido\PayIn\Model;


use \Magento\Framework\Webapi\Rest\Request;
use \Magento\Framework\DataObject;
use \Magento\Framework\App\ObjectManager;
use \Magento\Sales\Api\CreditmemoManagementInterface;
use \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader;
use \Magento\Sales\Helper\Data as SalesData;
use \Magento\Sales\Model\Order;
use \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use \Magento\Sales\Model\Order\Invoice;
use \Magento\Sales\Model\Service\InvoiceService;
use \Magento\Framework\DB\Transaction;
use \Psr\Log\LoggerInterface;

use \Liquido\PayIn\Helper\LiquidoSalesOrderHelper;
use \Liquido\PayIn\Helper\LiquidoCreditmemoHelper;
use \Liquido\PayIn\Helper\LiquidoSendEmail;
use \Liquido\PayIn\Helper\LiquidoConfigData;

class LiquidoWebhook
{

	private Request $request;
	private LiquidoSalesOrderHelper $liquidoSalesOrderHelper;
	private LiquidoCreditmemoHelper $liquidoCreditmemoHelper;
	private LiquidoSendEmail $sendEmail;
	private ObjectManager $objectManager;
	private InvoiceService $invoiceService;
	private Transaction $transaction;
	private LoggerInterface $logger;
	private LiquidoDeleteCreditmemo $liquidoDeleteCreditmemo; 
	private LiquidoConfigData $liquidoConfigData;
	private $salesData;
	private $creditmemoSender;
	private $creditmemoLoader;

	public function __construct(
		Request $request,
		LiquidoSalesOrderHelper $liquidoSalesOrderHelper,
		LiquidoCreditmemoHelper  $liquidoCreditmemoHelper,
		LiquidoSendEmail $sendEmail,
		InvoiceService $invoiceService,
		Transaction $transaction,
		LoggerInterface $logger,
		LiquidoDeleteCreditmemo $liquidoDeleteCreditmemo,
		LiquidoConfigData $liquidoConfigData,
		SalesData $salesData = null,
		CreditmemoSender $creditmemoSender,
		CreditmemoLoader $creditmemoLoader
	) {
		$this->request = $request;
		$this->liquidoSalesOrderHelper = $liquidoSalesOrderHelper;
		$this->liquidoCreditmemoHelper = $liquidoCreditmemoHelper;
		$this->creditmemoLoader = $creditmemoLoader;
		$this->sendEmail = $sendEmail;
		$this->invoiceService = $invoiceService;
		$this->transaction = $transaction;
		$this->logger = $logger;
		$this->liquidoDeleteCreditmemo = $liquidoDeleteCreditmemo;
		$this->liquidoConfigData = $liquidoConfigData;
		$this->objectManager = ObjectManager::getInstance();
		$this->salesData = $salesData ?: $this->objectManager->get(SalesData::class);
		$this->creditmemoSender = $creditmemoSender;
	}

	/**
	 * {@inheritdoc}
	 */
	public function processLiquidoCallbackRequest()
	{
		$className = static::class;
		$this->logger->info("###################### BEGIN {$className} processLiquidoCallbackRequest ######################");

		// *** TO DO: get headers from request

		$body = $this->request->getBodyParams();

		$eventType = $body["eventType"];
		// if $eventType == SOMETHING { do something... }

		// if "idempotencyKey" not in $body { do something... }
		$idempotencyKey = $this->getIdempotencyKey($body);
		$foundLiquidoSalesOrder = $this->liquidoSalesOrderHelper
			->findLiquidoSalesOrderByIdempotencyKey($idempotencyKey);

		$orderId = $foundLiquidoSalesOrder->getData('order_id');
		$liquidoSalesOrderAlreadyExists = $orderId != null;

		if ($liquidoSalesOrderAlreadyExists) 
		{
			// if "transferStatus" not in $body { do something... }
			$transferStatus = $body["data"]["chargeDetails"]["transferStatus"];
			$paymentMethod = $body["data"]["chargeDetails"]["paymentMethod"];
			$orderData = new DataObject(array(
				"orderId" => $orderId,
				"idempotencyKey" => $idempotencyKey,
				"transferStatus" => $transferStatus,
				"paymentMethod" => $paymentMethod
			));

			$order = $this->objectManager->create('\Magento\Sales\Model\Order')->loadByIncrementId($orderId);

			if (!$this->isRefund($eventType)) 
			{
				$this->logger->info("###################### IS NOT REFUND ######################");

				if ($order->canInvoice()) 
				{
					$invoice = $this->invoiceService->prepareInvoice($order);
					$invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
					$invoice->register();
					$invoice->save();

					$transactionSave = $this->transaction
						->addObject($invoice)
						->addObject($invoice->getOrder());
					$transactionSave->save();

					$order->addStatusHistoryComment(__('Invoice #' . $invoice->getIncrementId() . ' created automatically'))
						->setIsCustomerNotified(false)
						->save();	

					$this->liquidoSalesOrderHelper->createOrUpdateLiquidoSalesOrder($orderData);
				}
				// if ($paymentMethod == PaymentMethod::CASH) 
				// {
				// 	$params = array(
				// 		'name' => $body['data']['chargeDetails']['payer']['name'],
				// 		'email' => $body['data']['chargeDetails']['payer']['email'],
				// 		'cashCode' => $body['data']['chargeDetails']['transferDetails']['payCash']['referenceNumber'],
				// 		'statusCode' => $body['data']['chargeDetails']['transferStatusCode']
				// 	);
				// 	$this->sendEmail->sendEmail($params, true);
				// }
			}
			else
			{
				$this->logger->info("###################### IS REFUND ######################");

				$idempotencyKey = $body['data']['chargeDetails']['idempotencyKey'];
				$foundCreditmemo = $this->liquidoCreditmemoHelper->findCreditmemoByRefundIdempotencyKey($idempotencyKey);
				$creditmemoId = (int) $foundCreditmemo->getData('creditmemo_id');

				if (array_key_exists('transferStatus', $body['data']['chargeDetails']) && $body['data']['chargeDetails']['transferStatus'] != 'SETTLED')
				{
					try {
						$this->liquidoDeleteCreditmemo->deleteCreditmemo($creditmemoId);

						$creditmemoData = new DataObject(
							array(
								"orderId" => $orderId,
								"creditmemoId" => $foundCreditmemo->getData('creditmemo_id'),
								"idempotencyKey" => $idempotencyKey,
								"referenceId" => $body['data']['chargeDetails']['referenceId'],
								"transferStatus" => "FAILED"
							)
						);

						$emailParams = array(
							'country' => $this->liquidoConfigData->getCountry(),
							'name' => $body['data']['chargeDetails']['payer']['name'],
							'email' => $body['data']['chargeDetails']['payer']['email'],
							'creditmemoId' => $foundCreditmemo->getData('creditmemo_id'),
							'orderId' => $foundCreditmemo->getData('order_id')
						);

						$this->sendEmail->sendEmail($emailParams);

						$this->liquidoCreditmemoHelper->createOrUpdateLiquidoCreditmemo($creditmemoData);
					} catch (\Exception $e) {
						return [[
							"status" => 400,
							"message" => $e
						]];
					}
				}
				else
				{
					try {

						$creditmemoId = $foundCreditmemo->getData('creditmemo_id');

						if ($foundCreditmemo->getData('creditmemo_id') == null || empty($foundCreditmemo->getData('creditmemo_id')))
						{
							$creditmemoId = $this->refundOrder((int) $orderId, $idempotencyKey);

							$this->logger->info("###########Refund credit memo id#########", (array) $creditmemoId);
						}

						$creditmemoData = new DataObject(
							array(
								"orderId" => $orderId,
								"creditmemoId" => $creditmemoId,
								"idempotencyKey" => $idempotencyKey,
								"referenceId" => $body['data']['chargeDetails']['referenceId'],
								"transferStatus" => "REFUNDED"
							)
						);

						$this->liquidoCreditmemoHelper->createOrUpdateLiquidoCreditmemo($creditmemoData);
						
						if ($this->orderIsTotallyRefunded($orderId))
						{
							$this->liquidoSalesOrderHelper->updateLiquidoSalesOrderStatus($foundLiquidoSalesOrder, 'REFUNDED');
						}
					} catch (\Exception $e) {
						return [[
							"status" => 400,
							"message" => $e
						]];
					}
				}
			}
		}

		$this->logger->info("###################### END {$className} processLiquidoCallbackRequest ######################");

		return [[
			"status" => 200,
			"message" => "received"
		]];
	}

	private function isRefund($eventType)
	{
		if ($eventType === 'CHARGE_REFUND_SUCCEEDED')
		{
			return true;
		}
		elseif ($eventType === 'CHARGE_REFUND_FAILED')
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	private function getIdempotencyKey($bodyInfo)
	{
		if ($this->isRefund($bodyInfo["eventType"])) {
			return $bodyInfo["data"]["chargeDetails"]["referenceId"];
		} else {
			return $bodyInfo["data"]["chargeDetails"]["idempotencyKey"];
		}
	}

	private function orderIsTotallyRefunded($orderId)
	{
		$bool = false;
		$order = $this->objectManager->create('\Magento\Sales\Model\Order')->loadByIncrementId($orderId);
		if ($order->getStatus() == Order::STATE_CLOSED)
		{
			$bool = true;
		}

		return $bool;
	}

	private function refundOrder(int $orderId, $idempotencyKey)
	{
		try {
			$foundCreditmemo = $this->liquidoCreditmemoHelper->findCreditmemoByRefundIdempotencyKey($idempotencyKey);

			if ($foundCreditmemo != null)
			{
				$creditmemoData = json_decode($foundCreditmemo->getData('json'), true);

				$this->creditmemoLoader->setOrderId($orderId);
				$this->creditmemoLoader->setCreditmemo($creditmemoData);
				$creditmemo = $this->creditmemoLoader->load();

				$creditmemoManagement = $this->objectManager->create(CreditmemoManagementInterface::class);
                $creditmemo->getOrder()->setCustomerNoteNotify(!empty($creditmemoData['send_email']));
                $doOffline = isset($creditmemoData['do_offline']) ? (bool)$creditmemoData['do_offline'] : false;
                $refund = $creditmemoManagement->refund($creditmemo, $doOffline);

                if (!empty($creditmemoData['send_email']) && $this->salesData->canSendNewCreditMemoEmail()) {
                    $this->creditmemoSender->send($creditmemo);
                }

				$this->logger->info("Refunded*******", (array) $refund->getIncrementId());

				return $refund->getIncrementId();
			}
		} catch (\Exception $e) {
			$this->logger->error("Failed".$e);
		}
	}
}
