<?php

declare(strict_types = 1);

namespace Drupal\helfi_platform_config\Menu;

use Drupal\Core\Menu\MenuLinkTreeManipulatorsAlterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Filters out the untranslated menu links.
 *
 * @note this requires a patch from #3091246.
 */
final class FilterByLanguage implements EventSubscriberInterface {

  /**
   * Responds to MenuLinkTreeEvents::ALTER_MANUPULATORS event.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeManipulatorsAlterEvent $event
   *   The event to subscribe to.
   */
  public function filter(MenuLinkTreeManipulatorsAlterEvent $event) : void {
    $manipulators = &$event->getManipulators();
    $manipulators[] = [
      'callable' => 'menu_block_current_language_tree_manipulator::filterLanguages',
      'args' => [['menu_link_content']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      'menu.link_tree.alter_manipulators' => [
        ['filter'],
      ],
    ];
  }

}