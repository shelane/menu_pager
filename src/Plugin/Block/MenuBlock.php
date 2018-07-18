<?php

namespace Drupal\menu_pager\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Link;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Menu pager block.
 *
 * @Block(
 *   id = "menu_pager_block",
 *   admin_label = @Translation("Menu Pager"),
 *   category = @Translation("Menus"),
 *   deriver = "Drupal\menu_pager\Plugin\Derivative\MenuBlock",
 * )
 */
class MenuBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * The active menu trail service.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $menuActiveTrail;

  /**
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * Constructs a new MenuBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu tree service.
   * @param \Drupal\Core\Menu\MenuActiveTrailInterface $menu_active_trail
   *   The active menu trail service.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, MenuLinkTreeInterface $menu_tree, MenuActiveTrailInterface $menu_active_trail, MenuLinkManagerInterface $menu_link_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->menuTree = $menu_tree;
    $this->menuActiveTrail = $menu_active_trail;
    $this->menuLinkManager = $menu_link_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree'),
      $container->get('menu.active_trail'),
      $container->get('plugin.manager.menu.link')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;

    $form['menu_pager_restrict_to_parent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Restrict to parent'),
      '#default_value' => isset($config['menu_pager_restrict_to_parent']) ? $config['menu_pager_restrict_to_parent'] : '',
      '#description' => $this->t('If checked, only previous and next links with the same menu parent as the active menu link will be used.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['menu_pager_restrict_to_parent'] = $values['menu_pager_restrict_to_parent'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $block_menu = $this->getDerivativeId();
    $config = $this->getConfiguration();

    // Show block if current page is in active menu trail of this menu and
    // previous or next links exist.
    $menu_link = $this->menuActiveTrail->getActiveLink(NULL);

    if (
      (isset($menu_link))
      && ($menu_link->getMenuName() == $block_menu)
      && ($navigation = $this->menu_pager_get_navigation($menu_link, $config['menu_pager_restrict_to_parent']))
      && (isset($navigation['previous']) || isset($navigation['next']))
    ) {
      $items = array();

      // Previous link.
      if (!empty($navigation['previous'])) {
        $items['previous'] = [
          '#markup' => Link::fromTextAndUrl('<< ' . $navigation['previous']['link_title'], $navigation['previous']['url'])->toString(),
          '#wrapper_attributes' => ['class' => 'menu-pager-previous'],
        ];
      }

      // Next link.
      if (!empty($navigation['next'])) {
        $items['next'] = [
          '#markup' => Link::fromTextAndUrl($navigation['next']['link_title'] . ' >>', $navigation['next']['url'])->toString(),
          '#wrapper_attributes' => ['class' => 'menu-pager-next'],
        ];
      }

      return [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['menu-pager', 'clearfix']],
        '#attached' => ['library' => ['menu_pager/menu_pager']],
      ];

    }
  }

  /**
   * Returns array with previous and next links for a given $menu_link.
   *
   * @param $menu_link
   *   A menu link object.
   * @param $restrict_to_parent
   *   (optional) A boolean to indicate whether or not to restrict the previous
   *   and next links to the menu's parent. Defaults to FALSE.
   *
   * @return
   *   An array with 'previous' and 'next' links, if found.
   */
  public function menu_pager_get_navigation($menu_link, $restrict_to_parent = FALSE) {
    $navigation = &drupal_static(__FUNCTION__, array());
    $menu_name = $menu_link->getMenuName();

    if (!isset($navigation[$menu_name])) {
      // Build flat tree of main menu links.
      $parameters = new \Drupal\Core\Menu\MenuTreeParameters();
      $parameters->expandedParents;

      $tree = $this->menuTree->load($menu_name, $parameters);
      $manipulators = [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ];
      $tree = $this->menuTree->transform($tree, $manipulators);

      // Need to build api for ignore links
      $ignore = [];
      $flat_links = [];
      $this->menu_pager_flatten_tree($tree, $flat_links, $ignore);

      // Find previous and next links.
      while ($flat_link = current($flat_links)) {
        if ($flat_link['mlid'] === $menu_link->getPluginId()) {
          if (key($flat_links) === 0) {
            $previous = FALSE;
          }
          else {
            $previous = prev($flat_links);
            next($flat_links);
          }
          $next = next($flat_links);
          $plid = '';
          // Add if found and not restricting to parent, or both links share same
          // parent.
          if ($parent = $menu_link->getParent()) {
            $parent = $this->menuLinkManager->createInstance($parent);
            $plid = $parent->getPluginId();
          }
          if ($previous && (!$restrict_to_parent || $previous['plid'] === $plid)) {
            $navigation[$menu_name]['previous'] = $previous;
          }
          if ($next && (!$restrict_to_parent || $next['plid'] === $plid)) {
            $navigation[$menu_name]['next'] = $next;
          }
        }
        else {
          next($flat_links);
        }
      }
    }

    return $navigation[$menu_name];
  }

  /**
   * Recursively flattens tree of menu links.
   */
  public function menu_pager_flatten_tree($menu_links, &$flat_links, $ignore, $plid = '') {
    $menu_links = array_values($menu_links);
    foreach($menu_links as $key => $item) {
      $uuid = $item->link->getPluginId();
      $link_title = $item->link->getTitle();
      $url = $item->link->getUrlObject();
      $link_path = $url->toString();
      if (!in_array($link_path, $ignore) && $item->link->isEnabled()) {
        $flat_links[] = array(
          'mlid' => $uuid,
          'plid' => $plid,
          'link_path' => $link_path,
          'link_title' => $link_title,
          'url' => $url,
        );
      }

      if ($item->hasChildren) {
        $this->menu_pager_flatten_tree($item->subtree, $flat_links, $ignore, $uuid);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['url.path']);
  }
}
