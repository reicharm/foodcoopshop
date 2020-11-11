<?php
namespace App\Shell\Task;

use App\Lib\PdfWriter\InvoiceToCustomerPdfWriter;
use Cake\Core\Configure;
use Cake\I18n\FrozenDate;
use Queue\Shell\Task\QueueTask;
use Queue\Shell\Task\QueueTaskInterface;

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

class QueueGenerateInvoiceForCustomerTask extends QueueTask implements QueueTaskInterface {

    use UpdateActionLogTrait;

    public $timeout = 30;

    public $retries = 2;

    public $Customer;

    public $Invoice;

    public $OrderDetail;

    public $Payment;

    public function run(array $data, $jobId) : void
    {

        $customerId = $data['customerId'];
        $invoiceNumber = $data['invoiceNumber'];
        $invoiceDate = $data['invoiceDate'];
        $invoicePdfFile = $data['invoicePdfFile'];
        $paidInCash = $data['paidInCash'];
        $cronjobRunDay = $data['cronjobRunDay'];

        $this->Customer = $this->getTableLocator()->get('Customers');
        $this->Invoice = $this->getTableLocator()->get('Invoices');
        $this->OrderDetail = $this->getTableLocator()->get('OrderDetails');
        $this->Payment = $this->getTableLocator()->get('Payments');

        $pdfWriter = new InvoiceToCustomerPdfWriter();
        $data = $this->Customer->Invoices->getDataForCustomerInvoice($customerId);
        $pdfWriter->prepareAndSetData($data, $paidInCash, $invoiceNumber, $invoiceDate);
        $pdfWriter->setFilename($invoicePdfFile);
        $pdfWriter->writeFile();

        $newInvoice = $this->saveInvoice($data, $invoiceNumber, $invoicePdfFile, $cronjobRunDay);
        $this->linkReturnedDepositWithInvoice($data, $newInvoice->id);
        $this->updateOrderDetailOrderState($data);

    }

    private function updateOrderDetailOrderState($data)
    {
        foreach($data->active_order_details as $orderDetail) {
            $patchedEntity = $this->OrderDetail->patchEntity(
                $orderDetail,
                [
                    'order_state' => Configure::read('app.htmlHelper')->getOrderStateBilled(),
                ]
            );
            $this->OrderDetail->save($patchedEntity);
        }
    }

    private function linkReturnedDepositWithInvoice($data, $invoiceId)
    {
        foreach($data->returned_deposit['entities'] as $payment) {
            $paymentEntity = $this->Payment->patchEntity($payment, [
                'invoice_id' => $invoiceId,
            ]);
            $this->Payment->save($paymentEntity);
        }
    }

    private function saveInvoice($data, $invoiceNumber, $invoicePdfFile, $cronjobRunDay)
    {

        $invoicePdfFileForDatabase = str_replace(ROOT, '', $invoicePdfFile);
        $invoicePdfFileForDatabase = str_replace('\\', '/', $invoicePdfFileForDatabase);

        $invoiceData = [
            'id_customer' => $data->id_customer,
            'invoice_number' => $invoiceNumber,
            'filename' => $invoicePdfFileForDatabase,
            'created' => new FrozenDate($cronjobRunDay),
            'invoice_taxes' => [],
        ];
        foreach($data->tax_rates as $taxRate => $values) {
            $invoiceData['invoice_taxes'][] = [
                'tax_rate' => $taxRate,
                'total_price_tax_excl' => $values['sum_price_excl'],
                'total_price_tax_incl' => $values['sum_price_incl'],
                'total_price_tax' => $values['sum_tax'],
            ];
        }
        $invoiceEntity = $this->Invoice->newEntity($invoiceData);

        $newInvoice = $this->Invoice->save($invoiceEntity, [
            'associated' => 'InvoiceTaxes'
        ]);

        return $newInvoice;

    }

}
?>