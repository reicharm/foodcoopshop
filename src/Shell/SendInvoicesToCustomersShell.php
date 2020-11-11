<?php
/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 3.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
namespace App\Shell;

use Cake\Core\Configure;
use Cake\I18n\FrozenDate;

class SendInvoicesToCustomersShell extends AppShell
{

    public $cronjobRunDay;

    public function main()
    {
        parent::main();

        $this->Customer = $this->getTableLocator()->get('Customers');
        $this->Invoice = $this->getTableLocator()->get('Invoices');
        $this->QueuedJobs = $this->getTableLocator()->get('Queue.QueuedJobs');

        // $this->cronjobRunDay can is set in unit test
        if (!isset($this->args[0])) {
            $this->cronjobRunDay = Configure::read('app.timeHelper')->getCurrentDateTimeForDatabase();
        } else {
            $this->cronjobRunDay = $this->args[0];
        }

        $invoiceDate = (new FrozenDate($this->cronjobRunDay))->i18nFormat(Configure::read('app.timeHelper')->getI18Format('DateLong2'));

        $this->Customer->dropManufacturersInNextFind();
        $customers = $this->Customer->find('all', [
            'conditions' => [
                'Customers.active' => APP_ON,
            ],
            'contain' => [
                'AddressCustomers', // to make exclude happen using dropManufacturersInNextFind
            ],
        ]);

        foreach($customers as $customer) {

            $data = $this->Invoice->getDataForCustomerInvoice($customer->id_customer);

            if (!$data->new_invoice_necessary) {
                continue;
            }

            $year = Configure::read('app.timeHelper')->getYearFromDbDate($this->cronjobRunDay);
            $invoiceNumber = $this->Invoice->getNextInvoiceNumberForCustomer($year, $this->Invoice->getLastInvoiceForCustomer());
            $invoicePdfFile =  Configure::read('app.htmlHelper')->getInvoiceLink(
                $customer->name, $customer->id_customer, Configure::read('app.timeHelper')->formatToDbFormatDate($this->cronjobRunDay), $invoiceNumber
            );

            $this->QueuedJobs->createJob('GenerateInvoiceForCustomer', [
                'customerId' => $customer->id_customer,
                'customerName' => $customer->name,
                'invoiceNumber' => $invoiceNumber,
                'invoiceDate' => $invoiceDate,
                'cronjobRunDay' => $this->cronjobRunDay,
                'invoicePdfFile' => $invoicePdfFile,
                'paidInCash' => false,
            ]);

        }

    }

}
