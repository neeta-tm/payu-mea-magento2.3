<?php
/**
 * PayU_EasyPlus payment method source model
 *
 * @category    PayU
 * @package     PayU_EasyPlus
 * @author      Kenneth Onah
 * @copyright   PayU South Africa (http://payu.co.za)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayU\EasyPlus\Model\Config\Source\Payment;

class Method implements \Magento\Framework\Option\ArrayInterface
{
	// Load PayU payment methods
	protected $payments = array(
		'CREDITCARD' 		=> 'Credit Card',
		'PAYFLEX' 		    => 'Payflex',
		'CREDITCARD_PAYU' 	=> 'Credit Card (PayU)',
		'LOYALTY' 			=> 'Loyalty',
		'WALLET' 			=> 'Wallet',
		'WALLET_PAYU' 		=> 'Wallet (PayU)',
		'DISCOVERYMILES' 	=> 'Discovery Miles',
		'GLOBALPAY' 		=> 'Global Pay',
		'DEBITCARD' 		=> 'Debit Card',
		'EBUCKS' 			=> 'eBucks',
		'PAYPAL' 			=> 'Paypal',
		'EFT' 				=> 'EFT',
		'EFT_PRO' 			=> 'EFT Pro',
		'MASTERPASS' 		=> 'Master Pass',
		'RCS_PLC' 			=> 'RCS PLC',
		'RCS'				=> 'RCS',
		'FASTA'				=> 'FASTA Instant Credit',
		'MPESA'				=> 'MPESA',
		'AIRTEL_MONEY'		=> 'AIRTEL MONEY',
		'MOBILE_BANKING'	=> 'MOBILE BANKING',
		'MTN_MOBILE'		=> 'MTN MOBILE',
		'TIGOPESA'			=> 'TIGOPESA'
	);

	/**
	 * Options getter
	 *
	 * @return array
	 */
	public function toOptionArray()
	{
		$payments_array = array();
		foreach ($this->payments as $key => $value ) {
			$payments_array[] = array( 'value' => $key, 'label' => $value );
		}

		return $payments_array;
	}

	/**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
    	$payments_array = array();
    	foreach ($this->payments as $key => $value ) {
			$payments_array[$key] = $value ;
		}
		return $payments_array;
    }
}
