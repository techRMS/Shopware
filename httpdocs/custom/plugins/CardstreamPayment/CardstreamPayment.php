<?php

namespace CardstreamPayment;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Models\Payment\Payment;

class CardstreamPayment extends Plugin
{

  /**
   * @param InstallContext $context
   */
  public function install(InstallContext $context)
  {
      /** @var \Shopware\Components\Plugin\PaymentInstaller $installer */
      $installer = $this->container->get('shopware.plugin_payment_installer');

      $options = [
          'name' => 'payment_cardstream',
          'description' => 'Pay with Cardstream',
          'action' => 'CardstreamPaymentCtrl',
          'active' => 1,
          'position' => 0,
          'additionalDescription' =>
              '<div id="payment_desc">'
              . '  Pay safely and securely by UK credit and debit card with Cardstream.'
              . '</div>'
      ];
      $installer->createOrUpdate($context->getPlugin(), $options);
  }


  /**
   * @param UninstallContext $context
   */
  public function uninstall(UninstallContext $context)
  {
      $this->setActiveFlag($context->getPlugin()->getPayments(), false);
  }

  /**
   * @param DeactivateContext $context
   */
  public function deactivate(DeactivateContext $context)
  {
      $this->setActiveFlag($context->getPlugin()->getPayments(), false);
  }

  /**
   * @param ActivateContext $context
   */
  public function activate(ActivateContext $context)
  {
      $this->setActiveFlag($context->getPlugin()->getPayments(), true);
  }

  /**
   * @param Payment[] $payments
   * @param $active bool
   */
  private function setActiveFlag($payments, $active)
  {
      $em = $this->container->get('models');

      foreach ($payments as $payment) {
          $payment->setActive($active);
      }
      $em->flush();
  }

    public static function getSubscribedEvents()
    {
        return [
    'Enlight_Controller_Dispatcher_ControllerPath_Frontend_CardstreamPaymentCtrl' => 'registerController'];
    }

    public function registerController(\Enlight_Event_EventArgs $args)
    {
        return $this->getPath() . '/Controllers/Frontend/CardstreamPaymentCtrl.php';
    }
}
