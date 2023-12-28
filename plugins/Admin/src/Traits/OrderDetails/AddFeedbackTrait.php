<?php
declare(strict_types=1);

namespace Admin\Traits\OrderDetails;

use App\Mailer\AppMailer;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 3.7.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */

trait AddFeedbackTrait {

    public function addFeedback()
    {
        $this->RequestHandler->renderAs($this, 'json');

        $orderDetailId = (int) $this->getRequest()->getData('orderDetailId');
        $orderDetailFeedback = htmlspecialchars_decode(strip_tags(trim($this->getRequest()->getData('orderDetailFeedback')), '<strong><b><i><img>'));

        $this->OrderDetail = $this->getTableLocator()->get('OrderDetails');
        $orderDetail = $this->OrderDetail->find('all', [
            'conditions' => [
                'OrderDetails.id_order_detail' => $orderDetailId
            ],
            'contain' => [
                'Customers',
                'Products.Manufacturers.AddressManufacturers',
                'OrderDetailFeedbacks'
            ]
        ])->first();

        try {
            if (empty($orderDetail)) {
                throw new \Exception('orderDetail not found: ' . $orderDetailId);
            }
            if (!empty($orderDetail->order_detail_feedback)) {
                throw new \Exception('orderDetail already has a feedback: ' . $orderDetailId);
            }

            $entity = $this->OrderDetail->OrderDetailFeedbacks->newEntity(
                [
                    'customer_id' => $this->identity->getId(),
                    'id_order_detail' => $orderDetailId,
                    'text' => $orderDetailFeedback,
                ]
            );
            if ($entity->hasErrors()) {
                throw new \Exception(join(' ', $this->OrderDetail->OrderDetailFeedbacks->getAllValidationErrors($entity)));
            }

        } catch (\Exception $e) {
            return $this->sendAjaxError($e);
        }

        $result = $this->OrderDetail->OrderDetailFeedbacks->save($entity);

        $email = new AppMailer();
        $email->viewBuilder()->setTemplate('Admin.order_detail_feedback_add');
        $email->setTo($orderDetail->product->manufacturer->address_manufacturer->email)
            ->setSubject(__d('admin', '{0}_has_written_a_feedback_to_product_{1}.', [
                $orderDetail->customer->name,
                '"' . $orderDetail->product_name . '"',
            ])
        )
        ->setViewVars([
            'orderDetail' => $orderDetail,
            'identity' => $this->identity,
            'orderDetailFeedback' => $orderDetailFeedback,
        ]);
        $email->customerAnonymizationForManufacturers = false;

        $email->addToQueue();

        $this->Flash->success(__d('admin', 'The_feedback_was_saved_successfully_and_sent_to_{0}.', ['<b>' . $orderDetail->product->manufacturer->name . '</b>']));

        $this->ActionLog = $this->getTableLocator()->get('ActionLogs');
        $actionLogMessage = __d('admin', '{0}_has_written_a_feedback_to_product_{1}.', [
            '<b>' . $orderDetail->customer->name . '</b>',
            '<b>' . $orderDetail->product_name . '</b>',
        ]);
        $this->ActionLog->customSave('order_detail_feedback_added', $this->identity->getId(), $orderDetail->id_order_detail, 'order_details', $actionLogMessage . ' <div class="changed">' . $orderDetailFeedback . ' </div>');

        $this->set([
            'result' => $result,
            'status' => !empty($result),
            'msg' => 'ok',
        ]);
        $this->viewBuilder()->setOption('serialize', ['result', 'status', 'msg']);

    }

}
