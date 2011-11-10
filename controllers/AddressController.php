<?php
/*
* 2007-2011 PrestaShop 
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2011 PrestaShop SA
*  @version  Release: $Revision: 7809 $
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
if (Configuration::get('VATNUMBER_MANAGEMENT') and
 file_exists(_PS_MODULE_DIR_ . 'vatnumber/vatnumber.php'))
    include_once (_PS_MODULE_DIR_ . 'vatnumber/vatnumber.php');
class AddressControllerCore extends FrontController
{
    public $auth = true;
    public $guestAllowed = true;
    public $php_self = 'address.php';
    public $authRedirection = 'addresses.php';
    public $ssl = true;
    protected $_address;
    public function preProcess ()
    {
        parent::preProcess();
        if ($back = Tools::getValue('back'))
            self::$smarty->assign('back', Tools::safeOutput($back));
        if ($mod = Tools::getValue('mod'))
            self::$smarty->assign('mod', Tools::safeOutput($mod));
        if (Tools::isSubmit('ajax') and Tools::isSubmit('type')) {
            if (Tools::getValue('type') == 'delivery')
                $id_address = isset(self::$cart->id_address_delivery) ? (int) self::$cart->id_address_delivery : 0;
            elseif (Tools::getValue('type') == 'invoice')
                $id_address = (isset(self::$cart->id_address_invoice) and
                 self::$cart->id_address_invoice !=
                 self::$cart->id_address_delivery) ? (int) self::$cart->id_address_invoice : 0;
            else
                exit();
        } else
            $id_address = (int) Tools::getValue('id_address', 0);
        if ($id_address) {
            $this->_address = new Address((int) $id_address);
            if (Validate::isLoadedObject($this->_address) and
             Customer::customerHasAddress((int) (self::$cookie->id_customer), 
            (int) ($id_address))) {
                if (Tools::isSubmit('delete')) {
                    if (self::$cart->id_address_invoice == $this->_address->id)
                        unset(self::$cart->id_address_invoice);
                    if (self::$cart->id_address_delivery == $this->_address->id)
                        unset(self::$cart->id_address_delivery);
                    if ($this->_address->delete())
                        Tools::redirect('addresses.php');
                    $this->errors[] = Tools::displayError(
                    'This address cannot be deleted.');
                }
                self::$smarty->assign(
                array('address' => $this->_address, 
                'id_address' => (int) $id_address));
            } elseif (Tools::isSubmit('ajax'))
                exit();
            else
                Tools::redirect('addresses.php');
        }
        if (Tools::isSubmit('submitAddress')) {
            $address = new Address();
            $this->errors = $address->validateControler();
            $address->id_customer = (int) (self::$cookie->id_customer);
            if (! Tools::getValue('phone') and ! Tools::getValue('phone_mobile'))
                $this->errors[] = Tools::displayError(
                'You must register at least one phone number');
            if (! $country = new Country((int) $address->id_country) or
             ! Validate::isLoadedObject($country))
                die(Tools::displayError());
                /* US customer: normalize the address */
            if ($address->id_country == Country::getByIso('US')) {
                include_once (_PS_TAASC_PATH_ .
                 'AddressStandardizationSolution.php');
                $normalize = new AddressStandardizationSolution();
                $address->address1 = $normalize->AddressLineStandardization(
                $address->address1);
                $address->address2 = $normalize->AddressLineStandardization(
                $address->address2);
            }
            $zip_code_format = $country->zip_code_format;
            if ($country->need_zip_code) {
                if (($postcode = Tools::getValue('postcode')) and
                 $zip_code_format) {
                    $zip_regexp = '/^' . $zip_code_format . '$/ui';
                    $zip_regexp = str_replace(' ', '( |)', $zip_regexp);
                    $zip_regexp = str_replace('-', '(-|)', $zip_regexp);
                    $zip_regexp = str_replace('N', '[0-9]', $zip_regexp);
                    $zip_regexp = str_replace('L', '[a-zA-Z]', $zip_regexp);
                    $zip_regexp = str_replace('C', $country->iso_code, 
                    $zip_regexp);
                    if (! preg_match($zip_regexp, $postcode))
                        $this->errors[] = '<strong>' .
                         Tools::displayError('Zip/ Postal code') . '</strong> ' .
                         Tools::displayError('is invalid.') . '<br />' .
                         Tools::displayError('Must be typed as follows:') . ' ' .
                         str_replace('C', $country->iso_code, 
                        str_replace('N', '0', 
                        str_replace('L', 'A', $zip_code_format)));
                } elseif ($zip_code_format)
                    $this->errors[] = '<strong>' .
                     Tools::displayError('Zip/ Postal code') . '</strong> ' .
                     Tools::displayError('is required.');
                elseif ($postcode and
                 ! preg_match('/^[0-9a-zA-Z -]{4,9}$/ui', $postcode))
                    $this->errors[] = '<strong>' .
                     Tools::displayError('Zip/ Postal code') . '</strong> ' .
                     Tools::displayError('is invalid.') . '<br />' .
                     Tools::displayError('Must be typed as follows:') . ' ' .
                     str_replace('C', $country->iso_code, 
                    str_replace('N', '0', 
                    str_replace('L', 'A', $zip_code_format)));
            }
            if ($country->isNeedDni() and
             (! Tools::getValue('dni') or
             ! Validate::isDniLite(Tools::getValue('dni'))))
                $this->errors[] = Tools::displayError(
                'Identification number is incorrect or has already been used.');
            elseif (! $country->isNeedDni())
                $address->dni = NULL;
            if (Configuration::get('PS_TOKEN_ENABLE') == 1 and
             strcmp(Tools::getToken(false), Tools::getValue('token')) and
             self::$cookie->isLogged(true) === true)
                $this->errors[] = Tools::displayError('Invalid token');
            if ((int) ($country->contains_states) and ! (int) ($address->id_state))
                $this->errors[] = Tools::displayError(
                'This country requires a state selection.');
            if (! sizeof($this->errors)) {
                if (isset($id_address)) {
                    $country = new Country((int) ($address->id_country));
                    if (Validate::isLoadedObject($country) and
                     ! $country->contains_states)
                        $address->id_state = 0;
                    $address_old = new Address((int) $id_address);
                    if (Validate::isLoadedObject($address_old) and
                     Customer::customerHasAddress(
                    (int) self::$cookie->id_customer, (int) $address_old->id)) {
                        if ($address_old->isUsed()) {
                            $address_old->delete();
                            if (! Tools::isSubmit('ajax')) {
                                $to_update = false;
                                if (self::$cart->id_address_invoice ==
                                 $address_old->id) {
                                    $to_update = true;
                                    self::$cart->id_address_invoice = 0;
                                }
                                if (self::$cart->id_address_delivery ==
                                 $address_old->id) {
                                    $to_update = true;
                                    self::$cart->id_address_delivery = 0;
                                }
                                if ($to_update)
                                    self::$cart->update();
                            }
                        } else {
                            $address->id = (int) ($address_old->id);
                            $address->date_add = $address_old->date_add;
                        }
                    }
                } elseif (self::$cookie->is_guest)
                    Tools::redirect('addresses.php');
                if ($result = $address->save()) {
                    /* In order to select this new address : order-address.tpl */
                    if ((bool) (Tools::getValue('select_address', false)) == true or
                     (Tools::isSubmit('ajax') and
                     Tools::getValue('type') == 'invoice')) {
                        /* This new adress is for invoice_adress, select it */
                        self::$cart->id_address_invoice = (int) ($address->id);
                        self::$cart->update();
                    }
                    if (Tools::isSubmit('ajax')) {
                        $return = array('hasError' => ! empty($this->errors), 
                        'errors' => $this->errors, 
                        'id_address_delivery' => self::$cart->id_address_delivery, 
                        'id_address_invoice' => self::$cart->id_address_invoice);
                        die(Tools::jsonEncode($return));
                    }
                    Tools::redirect(
                    $back ? ($mod ? $back . '&back=' . $mod : $back) : 'addresses.php');
                }
                $this->errors[] = Tools::displayError(
                'An error occurred while updating your address.');
            }
        } elseif (! $id_address) {
            $customer = new Customer((int) (self::$cookie->id_customer));
            if (Validate::isLoadedObject($customer)) {
                $_POST['firstname'] = $customer->firstname;
                $_POST['lastname'] = $customer->lastname;
            }
        }
        if (Tools::isSubmit('ajax') and sizeof($this->errors)) {
            $return = array('hasError' => ! empty($this->errors), 
            'errors' => $this->errors);
            die(Tools::jsonEncode($return));
        }
    }
    public function setMedia ()
    {
        parent::setMedia();
        Tools::addJS(_THEME_JS_DIR_ . 'tools/statesManagement.js');
    }
    public function process ()
    {
        parent::process();
        /* Secure restriction for guest */
        if (self::$cookie->is_guest)
            Tools::redirect('addresses.php');
        if (Tools::isSubmit('id_country') and
         Tools::getValue('id_country') != NULL and
         is_numeric(Tools::getValue('id_country')))
            $selectedCountry = (int) Tools::getValue('id_country');
        elseif (isset($this->_address) and isset($this->_address->id_country) and
         ! empty($this->_address->id_country) and
         is_numeric($this->_address->id_country))
            $selectedCountry = (int) $this->_address->id_country;
        elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $array = preg_split('/,|-/', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if (! Validate::isLanguageIsoCode($array[0]) or
             ! ($selectedCountry = Country::getByIso($array[0])))
                $selectedCountry = (int) Configuration::get(
                'PS_COUNTRY_DEFAULT');
        } else
            $selectedCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        if (Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES'))
            $countries = Carrier::getDeliveredCountries(
            (int) self::$cookie->id_lang, true, true);
        else
            $countries = Country::getCountries((int) self::$cookie->id_lang, 
            true);
        $countriesList = '';
        foreach ($countries as $country)
            $countriesList .= '<option value="' . (int) ($country['id_country']) .
             '" ' .
             ($country['id_country'] == $selectedCountry ? 'selected="selected"' : '') .
             '>' . htmlentities($country['name'], ENT_COMPAT, 'UTF-8') .
             '</option>';
        if ((Configuration::get('VATNUMBER_MANAGEMENT') and
         file_exists(_PS_MODULE_DIR_ . 'vatnumber/vatnumber.php')) &&
         VatNumber::isApplicable(Configuration::get('PS_COUNTRY_DEFAULT')))
            self::$smarty->assign('vat_display', 2);
        elseif (Configuration::get('VATNUMBER_MANAGEMENT'))
            self::$smarty->assign('vat_display', 1);
        else
            self::$smarty->assign('vat_display', 0);
        self::$smarty->assign('ajaxurl', _MODULE_DIR_);
        self::$smarty->assign('vatnumber_ajax_call', 
        (int) file_exists(_PS_MODULE_DIR_ . 'vatnumber/ajax.php'));
        self::$smarty->assign(
        array('countries_list' => $countriesList, 'countries' => $countries, 
        'errors' => $this->errors, 'token' => Tools::getToken(false), 
        'select_address' => (int) (Tools::getValue('select_address'))));
    }
    protected function _processAddressFormat ()
    {
        $id_country = is_null($this->_address) ? 0 : (int) $this->_address->id_country;
        $dlv_adr_fields = AddressFormat::getOrderedAddressFields($id_country, 
        true, true);
        self::$smarty->assign('ordered_adr_fields', $dlv_adr_fields);
    }
    public function displayHeader ()
    {
        if (Tools::getValue('ajax') != 'true')
            parent::displayHeader();
    }
    public function displayContent ()
    {
        parent::displayContent();
        $this->_processAddressFormat();
        self::$smarty->display(_PS_THEME_DIR_ . 'address.tpl');
    }
    public function displayFooter ()
    {
        if (Tools::getValue('ajax') != 'true')
            parent::displayFooter();
    }
}

