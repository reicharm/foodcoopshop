<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Component\StringComponent;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use App\Services\CatalogService;

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
class CategoriesController extends FrontendController
{

    protected $BlogPost;
    protected $Category;

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        if (! (Configure::read('appDb.FCS_SHOW_PRODUCTS_FOR_GUESTS') || $this->identity->user())) {
            $this->identity->deny($this->getRequest()->getParam('action'));
        } else {
            $this->identity->allow($this->getRequest()->getParam('action'));
        }
    }

    public function newProducts()
    {
        $this->BlogPost = $this->getTableLocator()->get('BlogPosts');
        $blogPosts = $this->BlogPost->findBlogPosts($this->identity, null, true);
        $this->set('blogPosts', $blogPosts);

        $catalogService = new CatalogService();
        $products = $catalogService->getProducts($this->identity, Configure::read('app.categoryAllProducts'), true);
        $products = $catalogService->prepareProducts($this->identity, $products);
        $this->set('products', $products);

        $this->set('title_for_layout', __('New_products'));

        $this->render('detail');
    }

    public function search()
    {
        $keyword = '';
        if (! empty($this->getRequest()->getQuery('keyword'))) {
            $keyword = h(trim($this->getRequest()->getQuery('keyword')));
        }

        if ($keyword == '') {
            throw new RecordNotFoundException('no keyword');
        }

        $this->set('keyword', $keyword);

        $this->BlogPost = $this->getTableLocator()->get('BlogPosts');
        $blogPosts = $this->BlogPost->findBlogPosts($this->identity, null, true);
        $this->set('blogPosts', $blogPosts);

        $catalogService = new CatalogService();
        $products = $catalogService->getProducts($this->identity, Configure::read('app.categoryAllProducts'), false, $keyword);
        $products = $catalogService->prepareProducts($this->identity, $products);
        $this->set('products', $products);

        $this->set('title_for_layout', __('Search') . ' "' . $keyword . '"');

        $this->render('detail');
    }

    public function detail()
    {
        $categoryId = (int) $this->getRequest()->getParam('pass')[0];

        $this->Category = $this->getTableLocator()->get('Categories');
        $category = $this->Category->find('all', [
            'conditions' => [
                'Categories.id_category' => $categoryId,
                'Categories.active' => APP_ON,
            ]
        ])->first();

        if (empty($category)) {
            throw new RecordNotFoundException('category not found');
        }

        $correctSlug = StringComponent::slugify($category->name);
        $givenSlug = StringComponent::removeIdFromSlug($this->getRequest()->getParam('pass')[0]);
        if ($correctSlug != $givenSlug) {
            $this->redirect(Configure::read('app.slugHelper')->getCategoryDetail($categoryId, $category->name));
        }

        $this->BlogPost = $this->getTableLocator()->get('BlogPosts');
        $blogPosts = $this->BlogPost->findBlogPosts($this->identity, null, true);
        $this->set('blogPosts', $blogPosts);

        $catalogService = new CatalogService();
        $products = $catalogService->getProducts($this->identity, $categoryId);
        $products = $catalogService->prepareProducts($this->identity, $products);

        $this->set('products', $products);

        $this->set('category', $category);

        $this->set('title_for_layout', $category->name);
    }
}
