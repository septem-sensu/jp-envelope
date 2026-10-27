<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/view.php' );

/**
 * This class represents a role with its view permissions (roles.json).
 * Roles guard the views (roleIds in views.json) and the actions
 * (permissions are the whitelist within View::executeActions()): an
 * action without a role permission is skipped — a request that
 * explicitly triggers exactly such an action over the 'action'
 * parameter gets a visible permission error instead.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-10
 *
 * @example   $objRole = new Role( 'guest' );
 *
 */
class Role extends BaseObject {

  /**
   * The action names ("Class::method") the role is allowed to trigger.
   *
   * @var       array
   *
   */
  protected array  $permissions;

  /**
   * The view id the role lands on after login and logout.
   *
   * @var       string
   *
   */
  protected string $defaultViewId;

  /**
   * This method returns the permissions of the role.
   *
   * @return    array|null   The action names or null when not set
   *
   * @example   $arrPermissions = $objRole->permissions();
   *
   */
  public function permissions() : array | null {
    if( ! isset( $this->permissions ) ) return null;

    return $this->permissions;
  }

  /**
   * This method returns the default view id of the role.
   *
   * @return    string|null   The default view id or null when not set
   *
   * @example   $strViewId = $objRole->defaultViewId();
   *
   */
  public function defaultViewId() : string | null {
    if( ! isset( $this->defaultViewId ) ) return null;

    return $this->defaultViewId;
  }

}

// EOF
