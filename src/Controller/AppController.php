<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\OrderCustomerService;
use App\Services\IdentityService;
use App\Services\OutputFilter\OutputFilterService;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use hisorange\BrowserDetect\Parser as Browser;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
class AppController extends Controller
{
#
    public $protectEmailAddresses = false;
    public $identity = null;
    
    protected $Customer;
    protected $Manufacturer;

    public function initialize(): void
    {

        parent::initialize();

        $this->loadComponent('Authentication.Authentication');
        $this->loadComponent('RequestHandler', [
            'enableBeforeRedirect' => false
        ]);
        $this->loadComponent('Flash', [
            'clear' => true
        ]);
        $this->loadComponent('String');

        /*
        if (Configure::read('appDb.FCS_SELF_SERVICE_MODE_FOR_STOCK_PRODUCTS_ENABLED')) {
            $authenticate['BarCode'] = [
                'userModel' => 'Customers',
                'fields' => [
                    'identifier' => 'barCode'
                ],
                'finder' => 'auth' // CustomersTable::findAuth
            ];
        }
        */

        $this->paginate = [
            'limit' => 300000,
            'maxLimit' => 300000
        ];
    }

    public function beforeFilter(EventInterface $event)
    {

        $identity = (new IdentityService())->getIdentity();
        $this->identity = $identity;
        $this->set('identity', $identity);

        $orderCustomerService = new OrderCustomerService();
        $this->set('orderCustomerService', $orderCustomerService);

        if (!$this->getRequest()->is('json') && !$orderCustomerService->isOrderForDifferentCustomerMode()) {
            $this->loadComponent('FormProtection');
        }

        $isMobile = false;
        if (PHP_SAPI !== 'cli') {
            /** @phpstan-ignore-next-line */
            $isMobile = Browser::isMobile() && !Browser::isTablet();
        }
        $this->set('isMobile', $isMobile);

        if ($identity->isManufacturer()) {
            $this->Manufacturer = $this->getTableLocator()->get('Manufacturers');
            $manufacturer = $this->Manufacturer->find('all', [
                'conditions' => [
                    'Manufacturers.id_manufacturer' => $identity->getManufacturerId()
                ]
            ])->first();
            $variableMemberFee = $this->Manufacturer->getOptionVariableMemberFee($manufacturer->variable_member_fee);
            $this->set('variableMemberFeeForTermsOfUse', $variableMemberFee);
        }

        parent::beforeFilter($event);
    }

    public function afterFilter(EventInterface $event)
    {
        parent::afterFilter($event);

        $newOutput = $this->response->getBody()->__toString();
        if ($this->protectEmailAddresses) {
            $newOutput = OutputFilterService::protectEmailAdresses($newOutput);
        }
        
        if (Configure::check('app.outputStringReplacements')) {
            $newOutput = OutputFilterService::replace($newOutput, Configure::read('app.outputStringReplacements'));
        }
        $this->response = $this->response->withStringBody($newOutput);
    }

    /**
     * keep this method in a controller - does not work with AppAuthComponent::login
     * updates login data (after profile change for customer and manufacturer)
     */
    /*
    protected function renewAuthSession()
    {
        $this->Customer = $this->getTableLocator()->get('Customers');
        $customer = $this->Customer->find('all', [
            'conditions' => [
                'Customers.id_customer' => $this->identity->getUserId()
            ],
            'contain' => [
                'AddressCustomers'
            ]
        ])->first();
        if (!empty($customer)) {
            $this->identity = $customer; ???
        }
    }
    */

    public function getPreparedReferer()
    {
        return htmlspecialchars_decode($this->getRequest()->getData('referer'));
    }

    public function setCurrentFormAsFormReferer()
    {
        $this->set('referer', $this->getRequest()->getUri()->getPath());
    }

    public function setFormReferer()
    {
        $this->set('referer', !empty($this->getRequest()->getData('referer')) ? $this->getRequest()->getData('referer') : $this->referer());
    }

    /**
     * can be used for returning exceptions as json
     * try {
     *      $this->foo->bar();
     *  } catch (Exception $e) {
     *      return $this->sendAjaxError($e);
     *  }
     * @param $error
     */
    protected function sendAjaxError($error)
    {
        if ($this->getRequest()->is('json')) {
            $this->setResponse($this->getResponse()->withStatus(500));
            $response = [
                'status' => APP_OFF,
                'msg' => $error->getMessage()
            ];
            $this->set(compact('response'));
            $this->render('/Error/errorjson');
        }
    }

    /**
     * needs to be implemented if $this->identity->authorize = ['Controller'] is used
     */
    public function isAuthorized($user)
    {
        return true;
    }
}
