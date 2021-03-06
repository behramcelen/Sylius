<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Behat\Page\Shop\Checkout;

use Behat\Mink\Session;
use Behat\Mink\Element\NodeElement;
use Sylius\Behat\Page\SymfonyPage;
use Sylius\Behat\Service\Accessor\TableAccessorInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Intl\Intl;
use Symfony\Component\Routing\RouterInterface;

/**
 * @author Mateusz Zalewski <mateusz.zalewski@lakion.com>
 */
class SummaryPage extends SymfonyPage implements SummaryPageInterface
{
    /**
     * @var TableAccessorInterface
     */
    private $tableAccessor;

    /**
     * @param Session $session
     * @param array $parameters
     * @param RouterInterface $router
     * @param TableAccessorInterface $tableAccessor
     */
    public function __construct(
        Session $session,
        array $parameters,
        RouterInterface $router,
        TableAccessorInterface $tableAccessor
    ) {
        parent::__construct($session, $parameters, $router);

        $this->tableAccessor = $tableAccessor;
    }


    /**
     * {@inheritdoc}
     */
    public function getRouteName()
    {
        return 'sylius_shop_checkout_summary';
    }

    /**
     * {@inheritdoc}
     */
    public function hasItemWithProductAndQuantity($productName, $quantity)
    {
        $table = $this->getElement('items_table');

        try {
            $this->tableAccessor->getRowWithFields($table, ['item' => $productName, 'qty' => $quantity]);
        } catch (\InvalidArgumentException $exception) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function hasShippingAddress(AddressInterface $address)
    {
        $shippingAddress = $this->getElement('shipping_address')->getText();

        return $this->isAddressValid($shippingAddress, $address);
    }

    /**
     * {@inheritdoc}
     */
    public function hasBillingAddress(AddressInterface $address)
    {
        $billingAddress = $this->getElement('billing_address')->getText();

        return $this->isAddressValid($billingAddress, $address);
    }

    /**
     * {@inheritdoc}
     */
    public function hasShippingMethod(ShippingMethodInterface $shippingMethod)
    {
        if (!$this->hasElement('shipping_method')) {
            return false;
        }

        return false !== strpos($this->getElement('shipping_method')->getText(), $shippingMethod->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function hasPaymentMethod(PaymentMethodInterface $paymentMethod)
    {
        if (!$this->hasElement('payment_method')) {
            return false;
        }

        return false !== strpos($this->getElement('payment_method')->getText(), $paymentMethod->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function hasProductDiscountedUnitPriceBy(ProductInterface $product, $amount)
    {
        $productRowElement = $this->getProductRowElement($product);
        $discountedPriceElement = $productRowElement->find('css', 'td > s');
        if (null === $discountedPriceElement) {
            return false;
        }
        $priceElement = $discountedPriceElement->getParent();
        $prices = explode(' ', $priceElement->getText());
        $priceWithoutDiscount = $this->getPriceFromString($prices[0]);
        $priceWithDiscount = $this->getPriceFromString($prices[1]);
        $discount = $priceWithoutDiscount - $priceWithDiscount;

        return $discount === $amount;
    }

    /**
     * {@inheritdoc}
     */
    public function hasOrderTotal($total)
    {
        if (!$this->hasElement('order_total')) {
            return false;
        }

        return $this->getTotalFromString($this->getElement('order_total')->getText()) === $total;
    }

    /**
     * {@inheritdoc}
     */
    public function addNotes($notes)
    {
        $this->getElement('extra_notes')->setValue($notes);
    }

    /**
     * {@inheritdoc}
     */
    public function hasPromotionTotal($promotionTotal)
    {
        return false !== strpos($this->getElement('promotion_total')->getText(), $promotionTotal);
    }

    /**
     * {@inheritdoc}
     */
    public function hasPromotion($promotionName)
    {
        return false !== stripos($this->getElement('promotion_discounts')->getText(), $promotionName);
    }

    /**
     * {@inheritdoc}
     */
    public function hasTaxTotal($taxTotal)
    {
        return false !== strpos($this->getElement('tax_total')->getText(), $taxTotal);
    }

    /**
     * {@inheritdoc}
     */
    public function hasShippingTotal($price)
    {
        return false !== strpos($this->getElement('shipping_total')->getText(), $price);
    }
    
    public function confirmOrder()
    {
        $this->getDocument()->pressButton('Place order');
    }

    public function changeAddress()
    {
        $this->getElement('addressing_step_label')->click();
    }

    public function changeShippingMethod()
    {
        $this->getElement('shipping_step_label')->click();
    }

    public function changePaymentMethod()
    {
        $this->getElement('payment_step_label')->click();
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefinedElements()
    {
        return array_merge(parent::getDefinedElements(), [
            'addressing_step_label' => '.steps a:contains("Addressing")',
            'billing_address' => '#addresses div:contains("Billing address") address',
            'extra_notes' =>'#sylius_checkout_summary_notes',
            'items_table' => '#items table',
            'order_total' => 'td:contains("Total")',
            'payment_method' => '#sylius-checkout-summary-payment-method',
            'payment_step_label' => '.steps a:contains("Payment")',
            'product_row' => 'tbody tr:contains("%name%")',
            'promotion_discounts' => '#promotion-discounts',
            'promotion_total' => '#promotion-total',
            'shipping_address' => '#addresses div:contains("Shipping address") address',
            'shipping_method' => '#sylius-checkout-summary-shipping-method',
            'shipping_step_label' => '.steps a:contains("Shipping")',
            'shipping_total' => '#shipping-total',
            'tax_total' => '#tax-total',
        ]);
    }

    /**
     * @param ProductInterface $product
     *
     * @return NodeElement
     */
    private function getProductRowElement(ProductInterface $product)
    {
        return $this->getElement('product_row', ['%name%' => $product->getName()]);
    }

    /**
     * @param string $displayedAddress
     * @param AddressInterface $address
     *
     * @return bool
     */
    private function isAddressValid($displayedAddress, AddressInterface $address)
    {
        return
            $this->hasAddressPart($displayedAddress, $address->getCompany(), true) &&
            $this->hasAddressPart($displayedAddress, $address->getFirstName()) &&
            $this->hasAddressPart($displayedAddress, $address->getLastName()) &&
            $this->hasAddressPart($displayedAddress, $address->getPhoneNumber(), true) &&
            $this->hasAddressPart($displayedAddress, $address->getStreet()) &&
            $this->hasAddressPart($displayedAddress, $address->getCity()) &&
            $this->hasAddressPart($displayedAddress, $address->getProvinceCode(), true) &&
            $this->hasAddressPart($displayedAddress, $this->getCountryName($address->getCountryCode())) &&
            $this->hasAddressPart($displayedAddress, $address->getPostcode())
        ;
    }

    /**
     * @param string $address
     * @param string $addressPart
     *
     * @return bool
     */
    private function hasAddressPart($address, $addressPart, $optional = false)
    {
        if ($optional && null === $addressPart) {
            return true;
        }

        return false !== strpos($address, $addressPart);
    }

    /**
     * @param string $countryCode
     *
     * @return string
     */
    private function getCountryName($countryCode)
    {
        return strtoupper(Intl::getRegionBundle()->getCountryName($countryCode, 'en'));
    }

    /**
     * @param string $price
     *
     * @return int
     */
    private function getPriceFromString($price)
    {
        return (int) round((str_replace(['€', '£', '$'], '', $price) * 100), 2);
    }

    /**
     * @param string $total
     * 
     * @return int
     */
    private function getTotalFromString($total)
    {
        $total = str_replace('Total:', '', $total);

        return $this->getPriceFromString($total);
    }
}
