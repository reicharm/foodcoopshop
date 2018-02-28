<?php
namespace Admin\Controller;
use App\Auth\AppPasswordHasher;
use App\Mailer\AppEmail;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Network\Exception\NotFoundException;
use Cake\ORM\TableRegistry;

/**
 * CustomersController
 *
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, http://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */

class CustomersController extends AdminAppController
{

    public function isAuthorized($user)
    {
        switch ($this->request->action) {
            case 'edit':
                return $this->AppAuth->isSuperadmin();
                break;
            case 'profile':
                return $this->AppAuth->isSuperadmin() || $this->AppAuth->isAdmin() || $this->AppAuth->isCustomer();
                break;
            case 'changePassword':
                return $this->AppAuth->user();
                break;
            default:
                return $this->AppAuth->isSuperadmin() || $this->AppAuth->isAdmin();
                break;
        }
    }

    public function ajaxEditGroup()
    {
        $customerId = (int) $this->request->getData('customerId');
        $groupId = (int) $this->request->getData('groupId');

        if (! in_array($groupId, array_keys(Configure::read('app.htmlHelper')->getAuthDependentGroups($this->AppAuth->getGroupId())))) {
            $message = 'user group not allowed: ' . $groupId;
            $this->log($message);
            die(json_encode([
                'status' => 0,
                'msg' => $message
            ]));
        }

        $this->Customer = TableRegistry::get('Customers');
        $oldCustomer = $this->Customer->find('all', [
            'conditions' => [
                'Customers.id_customer' => $customerId
            ]
        ])->first();
        $oldGroup = $oldCustomer->id_default_group;

        // eg. member is not allowed to change groupId of admin, not even to set a groupid he would be allowed to (member)
        if ($this->AppAuth->getGroupId() < $oldCustomer->id_default_group) {
            $message = 'logged user has lower groupId than the user he wants to edit: customerId: ' . $oldCustomer->id_customer . ', groupId: ' . $oldCustomer->id_default_group;
            $this->log($message);
            die(json_encode([
                'status' => 0,
                'msg' => $message
            ]));
        }

        $this->Customer->save(
            $this->Customer->patchEntity(
                $oldCustomer,
                [
                    'id_default_group' => $groupId
                ]
            )
        );

        $messageString = 'Die Gruppe des Mitglieds <b>' . $oldCustomer->name . '</b> wurde von <b>' . Configure::read('app.htmlHelper')->getGroupName($oldGroup) . '</b> auf <b>' . Configure::read('app.htmlHelper')->getGroupName($groupId) . '</b> geändert.';
        $this->Flash->success($messageString);
        $this->ActionLog = TableRegistry::get('ActionLogs');
        $this->ActionLog->customSave('customer_group_changed', $this->AppAuth->getUserId(), $customerId, 'customers', $messageString);

        die(json_encode([
            'status' => 1
        ]));
    }

    public function changePassword()
    {
        $this->set([
            'title_for_layout' => __('Change your password')
        ]);

        if (empty($this->request->data)) {
            return;
        }

        $password = $this->request->data['Customers']['passwd'];
        $passwordNew1 = $this->request->data['Customers']['passwd_new_1'];
        $passwordNew2 = $this->request->data['Customers']['passwd_new_2'];

        $error = 0;

        $ph = new AppPasswordHasher();

        if (! $this->Customer->isCustomerPassword($this->AppAuth->getUserId(), $ph->hash($password))) {
            $this->Customer->invalidate('passwd', __('Your old Password was wrong'));
            $error ++;
        }

        if ($password == $ph->hash('')) {
            $this->Customer->invalidate('passwd', __('Insert your new Password'));
            $error ++;
        }

        if (! preg_match(PASSWORD_REGEX, $passwordNew1)) {
            $errorMessage = __('The length of the password should be from 6 - 32 Chars');
            $this->Customer->invalidate('passwd_new_1', $errorMessage);
            $this->Customer->invalidate('passwd_new_2', $errorMessage);
            $error ++;
        }

        if ($passwordNew1 != $passwordNew2) {
            $errorMessage = __('the new passwords are not the same');
            $this->Customer->invalidate('passwd_new_1', $errorMessage);
            $this->Customer->invalidate('passwd_new_2', $errorMessage);
            $error ++;
        }

        if ($password == $ph->hash($passwordNew1)) {
            $errorMessage = __('your new and your old Password dont differ, please change');
            $this->Customer->invalidate('passwd_new_1', $errorMessage);
            $this->Customer->invalidate('passwd_new_2', $errorMessage);
            $error ++;
        }

        if ($passwordNew1 == '') {
            $errorMessage = __('Please set your password');
            $this->Customer->invalidate('passwd_new_1', $errorMessage);
            $this->Customer->invalidate('passwd_new_2', $errorMessage);
            $error ++;
        }

        if ($error > 0) {
            $this->Flash->error(__('An error occurred, please check your form.'));
            return;
        }

        $customer2save = [
            'passwd' => $ph->hash($passwordNew1)
        ];

        $this->Customer->id = $this->AppAuth->getUserId();
        if ($this->Customer->save($customer2save, false)) {
            if ($this->AppAuth->isManufacturer()) {
                $message = 'Der Hersteller ' . $this->AppAuth->getManufacturerName();
                $actionLogType = 'manufacturer_password_changed';
                $actionLogId = $this->AppAuth->getManufacturerId();
                $actionLogModel = 'manufacturers';
            } else {
                $message = 'Das Mitglied ' . $this->AppAuth->getUsername();
                $actionLogType = 'customer_password_changed';
                $actionLogId = $this->AppAuth->getUserId();
                $actionLogModel = 'customers';
            }
            $message .= ' hat sein Passwort geändert.';

            $this->ActionLog = TableRegistry::get('ActionLogs');
            $this->ActionLog->customSave($actionLogType, $this->AppAuth->getUserId(), $actionLogId, $actionLogModel, $message);
            $this->Flash->success(__('your new password successfully set'));
            $this->redirect($this->referer());
        }
    }
    
    public function profile()
    {
        $this->set('title_for_layout', 'Mein Profil bearbeiten');
        $this->_processForm($this->AppAuth->getUserId());
        if (empty($this->request->getData())) {
            $this->render('edit');
        }
    }
    
    public function edit($customerId)
    {
        if ($customerId === null) {
            throw new NotFoundException;
        }
        $this->set('title_for_layout', 'Profil bearbeiten');
        $this->_processForm($customerId);
        if (empty($this->request->getData())) {
            $this->render('edit');
        }
    }
    
    private function _processForm($customerId)
    {
        
        $isOwnProfile = $this->AppAuth->getUserId() == $customerId;
        $this->set('isOwnProfile', $isOwnProfile);
        
        $this->Customer = TableRegistry::get('Customers');
        $customer = $this->Customer->find('all', [
            'conditions' => [
                'Customers.id_customer' => $customerId
            ],
            'contain' => [
                'AddressCustomers'
            ]
        ])->first();

        $this->setFormReferer();
        
        if (empty($this->request->getData())) {
            $this->set('customer', $customer);
            return;
        }
        
        $this->loadComponent('Sanitize');
        $this->request->data = $this->Sanitize->trimRecursive($this->request->getData());
        $this->request->data = $this->Sanitize->stripTagsRecursive($this->request->getData());
        
        $this->request->data['Customers']['email'] = $this->request->getData('Customers.address_customer.email');
        $this->request->data['Customers']['address_customer']['firstname'] = $this->request->getData('Customers.firstname');
        $this->request->data['Customers']['address_customer']['lastname'] = $this->request->getData('Customers.lastname');
        
        $customer = $this->Customer->patchEntity(
            $customer,
            $this->request->getData(),
            [
                'validate' => 'edit',
                'associated' => [
                    'AddressCustomers'
                ]
            ]
        );
        
        if (!empty($customer->getErrors())) {
            $this->Flash->error('Beim Speichern sind Fehler aufgetreten!');
            $this->set('customer', $customer);
            $this->render('edit');
        } else {
            
            $this->Customer->save(
                $customer,
                [
                    'associated' => [
                        'AddressCustomers'
                    ]
                ]
            );
            
            $this->ActionLog = TableRegistry::get('ActionLogs');
            if ($isOwnProfile) {
                $message = 'Dein Profil wurde geändert.';
            } else {
                $message = 'Das Profil von <b>' . $customer->name . '</b> wurde geändert.';
            }
            $this->ActionLog->customSave('customer_profile_changed', $this->AppAuth->getUserId(), $customer->id_customer, 'customers', $message);
            $this->Flash->success($message);
            
            $this->request->getSession()->write('highlightedRowId', $customer->id_customer);
            
            if ($this->request->here == Configure::read('app.slugHelper')->getCustomerProfile()) {
                $this->renewAuthSession();
            }
            
            $this->redirect($this->request->getData('referer'));
            
        }
        
        $this->set('customer', $customer);
        
    }

    /**
     * generates pdf on-the-fly
     */
    private function generateTermsOfUsePdf($customer)
    {
        $this->set('customer', $customer);
        $this->set('saveParam', 'I');
        $this->RequestHandler->renderAs($this, 'pdf');
        return $this->render('generateTermsOfUsePdf');
    }

    public function changeStatus($customerId, $status, $sendEmail)
    {
        if (! in_array($status, [
            APP_OFF,
            APP_ON
        ])) {
            throw new RecordNotFoundException('Status muss 0 oder 1 sein!');
        }

        $this->Customer->id = $customerId;
        $this->Customer->save([
            'active' => $status
        ]);

        $customer = $this->Customer->find('all', [
            'conditions' => [
                'Customers.id_customer' => $customerId
            ]
        ])->first();

        $statusText = 'deaktiviert';
        $actionLogType = 'customer_set_inactive';
        if ($status) {
            $statusText = 'aktiviert';
            $actionLogType = 'customer_set_active';
        }

        $message = 'Das Mitglied "' . $customer['Customers']['name'] . '" wurde erfolgreich ' . $statusText;

        if ($sendEmail) {
            // set new password
            $newPassword = $this->Customer->setNewPassword($customer['Customers']['id_customer']);

            $email = new AppEmail();
            $email->setTemplate('customer_activated')
                ->setTo($customer['Customers']['email'])
                ->setSubject('Dein Mitgliedskonto wurde aktiviert.')
                ->setViewVars([
                'appAuth' => $this->AppAuth,
                'data' => $customer,
                'newPassword' => $newPassword
                ]);

            $email->addAttachments(['Nutzungsbedingungen.pdf' => ['data' => $this->generateTermsOfUsePdf($customer['Customers']), 'mimetype' => 'application/pdf']]);
            $email->send();

            $message .= ' und eine Info-Mail an ' . $customer['Customers']['email'] . ' versendet';
        }

        $message .= '.';

        $this->Flash->success($message);

        $this->ActionLog = TableRegistry::get('ActionLogs');
        $this->ActionLog->customSave($actionLogType, $this->AppAuth->getUserId(), $customerId, 'customer', $message);

        $this->redirect($this->referer());
    }

    public function editComment()
    {
        $this->RequestHandler->renderAs($this, 'ajax');

        $customerId = $this->request->getData('customerId');
        $customerComment = htmlspecialchars_decode($this->request->getData('customerComment'));

        $oldCustomer = $this->Customer->find('all', [
            'conditions' => [
                'Customers.id_customer' => $customerId
            ]
        ])->first();

        $customerAddress2update = [
            'comment' => $customerComment
        ];

        $this->Customer->AddressCustomers->id = $oldCustomer['AddressCustomers']['id_address'];
        $this->Customer->AddressCustomers->save($customerAddress2update);

        $this->Flash->success('Der Kommentar wurde erfolgreich geändert.');

        $this->ActionLog = TableRegistry::get('ActionLogs');
        $this->ActionLog->customSave('customer_comment_changed', $this->AppAuth->getUserId(), $customerId, 'customers', 'Der Kommentar des Mitglieds "' . $oldCustomer['Customers']['name'] . '" wurde geändert: <br /><br /> alt: <div class="changed">' . $oldCustomer['AddressCustomers']['comment'] . '</div>neu: <div class="changed">' . $customerComment . ' </div>');

        die(json_encode([
            'status' => 1,
            'msg' => 'ok'
        ]));
    }

    public function index()
    {
        $active = 1; // default value
        if (in_array('active', array_keys($this->request->getQueryParams()))) {
            $active = $this->request->getQuery('active');
        }
        $this->set('active', $active);

        $validOrdersCountFrom = ''; // default value
        if (!empty($this->request->getQuery('validOrdersCountFrom'))) {
            $validOrdersCountFrom = $this->request->getQuery('validOrdersCountFrom');
        }
        $this->set('validOrdersCountFrom', $validOrdersCountFrom);

        $validOrdersCountTo = ''; // default value
        if (!empty($this->request->getQuery('validOrdersCountTo'))) {
            $validOrdersCountTo = $this->request->getQuery('validOrdersCountTo');
        }
        $this->set('validOrdersCountTo', $validOrdersCountTo);

        $dateFrom = '';
        if (! empty($this->request->getQuery('dateFrom'))) {
            $dateFrom = $this->request->getQuery('dateFrom');
        }
        $this->set('dateFrom', $dateFrom);

        $dateTo = '';
        if (! empty($this->request->getQuery('dateTo'))) {
            $dateTo = $this->request->getQuery('dateTo');
        }
        $this->set('dateTo', $dateTo);

        $conditions = [];
        if ($active != 'all') {
            $conditions = [
                'Customers.active' => $active
            ];
        }

        $this->Customer = TableRegistry::get('Customers');
        $conditions[] = $this->Customer->getConditionToExcludeHostingUser();

        $this->Customer->dropManufacturersInNextFind();
        $query = $this->Customer->find('all', [
            'conditions' => $conditions,
            'contain' => [
                'AddressCustomers', // to make exclude happen using dropManufacturersInNextFind
                'ValidOrders',
                'PaidCashFreeOrders'
            ]
        ]);

        $customers = $this->paginate($query, [
            'sortWhitelist' => [
                'Customers.' . Configure::read('app.customerMainNamePart'), 'Customers.id_default_group', 'Customers.id_default_group', 'Customers.email', 'Customers.active', 'Customers.newsletter', 'Customers.date_add'
            ],
            'order' => [
                'Customers.' . Configure::read('app.customerMainNamePart') => 'ASC'
            ]
        ])->toArray();

        $i = 0;
        $this->Payment = TableRegistry::get('Payments');
        $this->Order = TableRegistry::get('Orders');
        foreach ($customers as $customer) {
            if (Configure::read('app.htmlHelper')->paymentIsCashless()) {
                $paymentProductSum = $this->Payment->getSum($customer->id_customer, 'product');
                $paymentPaybackSum = $this->Payment->getSum($customer->id_customer, 'payback');
                $paymentDepositSum = $this->Payment->getSum($customer->id_customer, 'deposit');

                $sumTotalProduct = 0;
                $sumTotalDeposit = 0;
                foreach ($customer->paid_cash_free_orders as $paidCashFreeOrder) {
                    $sumTotalProduct += $paidCashFreeOrder->total_paid;
                    if (Configure::read('app.isDepositPaymentCashless') && strtotime($paidCashFreeOrder->date_add) > strtotime(Configure::read('app.depositPaymentCashlessStartDate'))) {
                        $sumTotalDeposit += $paidCashFreeOrder['total_deposit'];
                    }
                }
                // sometimes strange values like 2.8421709430404E-14 appear
                $customer->payment_product_delta = round($paymentProductSum - $paymentPaybackSum - $sumTotalProduct, 2);
                $customer->payment_deposit_delta = round($paymentDepositSum - $sumTotalDeposit, 2);

                // combine deposit delta in product delta to show same credit balance in list like in personal payment product page
                if (Configure::read('app.isDepositPaymentCashless')) {
                    $customer->payment_product_delta += $customer->payment_deposit_delta;
                }
            }

            $customer->order_count = $this->Order->getCountByCustomerId($customer->id_customer);

            $voc = count($customer->valid_orders);
            $customer->valid_orders_count = $voc;

            $customer->last_valid_order_date = '';

            $validOrdersCountCondition = true;
            if ($validOrdersCountFrom != '') {
                $validOrdersCountCondition = $voc >= $validOrdersCountFrom;
            }
            if ($validOrdersCountTo != '') {
                if ($validOrdersCountCondition === true) {
                    $validOrdersCountCondition = $voc <= $validOrdersCountTo;
                }
            }

            if (! $validOrdersCountCondition) {
                unset($customers[$i]);
                $i ++;
                continue;
            }

            if (isset($customer->valid_orders[$voc - 1])) {
                $lastOrderDate = $customer->valid_orders[$voc - 1]->date_add->i18nFormat(Configure::read('DateFormat.Database'));

                $lastOrderDateCondition = true;
                if ($dateFrom != '') {
                    $lastOrderDateCondition = strtotime($dateFrom) <= strtotime($lastOrderDate);
                }
                if ($dateTo != '') {
                    if ($lastOrderDateCondition === true) {
                        $lastOrderDateCondition = strtotime($dateTo) >= strtotime($lastOrderDate);
                    }
                }

                $customer->last_valid_order_date = $lastOrderDate;

                if (! $lastOrderDateCondition) {
                    unset($customer);
                }
            }

            $i ++;
        }
        $this->set('customers', $customers);

        $this->set('manufacturerDepositMoneySum', $this->Payment->getManufacturerDepositMoneySum());
        $this->set('title_for_layout', 'Mitglieder');
    }
}
