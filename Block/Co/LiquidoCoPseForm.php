<?php

namespace Liquido\PayIn\Block\Co;

use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;
use \Magento\Checkout\Model\Session;

use \Liquido\PayIn\Util\Co\LiquidoCoPayInMethod;
use \Liquido\PayIn\Model\Brl\LiquidoBrlPayInSession;

class LiquidoCoPseForm extends Template
{

    protected Session $checkoutSession;
    private LiquidoBrlPayInSession $payInSession;

    public function __construct(
        Context $context,
        Session $checkoutSession,
        LiquidoBrlPayInSession $payInSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->payInSession = $payInSession;
        parent::__construct($context);
    }

    public function getPsePayInMethodName()
    {
        return LiquidoCoPayInMethod::PSE["title"];
    }

    public function getPseFinancialInstitutionsList()
    {
        return $this->payInSession->getData('pseResultData')->getData('banks');
    }
}
