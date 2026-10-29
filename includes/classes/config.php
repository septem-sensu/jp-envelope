<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/view.php' );
require_once ( __DIR__ . '/../classes/role.php' );

/**
 * This class represents the app configuration (configs.json).
 * Every access goes through the 'default' scope instance
 * (BaseObject::config()) — filenames, namespace alias, version and
 * the technical rules like the password rules. A version bump here also
 * moves the registry id of the app envelope (appAlias_version) and busts the
 * browser cache of all statically referenced CSS/JS files (?v= suffix).
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-10
 *
 * @example   $objConfig = BaseObject::config();
 *
 */
class Config extends BaseObject {

  /**
   * The app version (used as ?v= cache buster and in the app envelope id).
   *
   * @var       string
   *
   */
  protected string $version;

  /**
   * The app name.
   *
   * @var       string
   *
   */
  protected string $appName;

  /**
   * The global JS namespace alias (window[appAlias]).
   *
   * @var       string
   *
   */
  protected string $appAlias;

  /**
   * The name of the auth cookie.
   *
   * @var       string
   *
   */
  protected string $cookieName;

  /**
   * The id of the default role (guest) for redirects and resolution fallbacks.
   *
   * @var       string
   *
   */
  protected string $defaultRoleId;

  /**
   * The id of the default user (guest) for the auth resolution fallback.
   *
   * @var       string
   *
   */
  protected string $defaultUserId;

  /**
   * The relative path of the JS directory for on-demand loading.
   *
   * @var       string
   *
   */
  protected string $jsPath;

  /**
   * The relative path of the CSS directory for on-demand loading.
   *
   * @var       string
   *
   */
  protected string $cssPath;

  /**
   * The relative path of the app brand image file.
   *
   * @var       string
   *
   */
  protected string $appBrandImagePath;

    /**
   * The state of the debug mode (true: debug mode on, false: debug mode off).
   *
   * @var       bool
   *
   */
  protected bool $debug;

  /**
   * The password rules for the frontend FieldValidator and the
   * server-side password checks.
   *
   * @var       object
   *
   */
  protected object $passwordRules;

  /**
   * This method returns the app version.
   *
   * @return    string   The app version
   *
   * @example   $strVersion = $objConfig->version();
   *
   */
  public function version() : string {
    return $this->version;
  }

  /**
   * This method returns the global JS namespace alias.
   *
   * @return    string   The app alias
   *
   * @example   $strAppAlias = $objConfig->appAlias();
   *
   */
  public function appAlias() : string {
    return $this->appAlias;
  }

  /**
   * This method returns the name of the auth cookie.
   *
   * @return    string   The cookie name
   *
   * @example   $strCookieName = $objConfig->cookieName();
   *
   */
  public function cookieName() : string {
    return $this->cookieName;
  }

  /**
   * This method returns the id of the default role (guest).
   *
   * @return    string   The default role id
   *
   * @example   $strRoleId = $objConfig->defaultRoleId();
   *
   */
  public function defaultRoleId() : string {
    return $this->defaultRoleId;
  }

  /**
   * This method returns the default role instance (guest).
   *
   * @return    Role   The default role instance
   *
   * @example   $objRole = $objConfig->defaultRole();
   *
   */
  public function defaultRole() : Role {
    return new Role( $this->defaultRoleId );
  }

  /**
   * This method returns the id of the default user (guest).
   *
   * @return    string   The default user id
   *
   * @example   $strUserId = $objConfig->defaultUserId();
   *
   */
  public function defaultUserId() : string {
    return $this->defaultUserId;
  }

  /**
   * This method returns the relative path of the JS directory.
   *
   * @return    string   The JS path
   *
   * @example   $strJsPath = $objConfig->jsPath();
   *
   */
  public function jsPath() : string {
    return $this->jsPath;
  }

  /**
   * This method returns the relative path of the CSS directory.
   *
   * @return    string   The CSS path
   *
   * @example   $strCssPath = $objConfig->cssPath();
   *
   */
  public function cssPath() : string {
    return $this->cssPath;
  }

  /**
   * This method returns the relative path of the app brand image.
   *
   * @return    string   The image path of the app brand
   *
   * @example   $strAppBrandImagePath = $objConfig->appBrandImagePath();
   *
   */
  public function appBrandImagePath() : string {
    return $this->appBrandImagePath;
  }

  /**
   * This method returns the state of the debug mode (true: debug mode on, false: debug mode off).
   *
   * @return    bool   The state of the debug mode (true: debug mode on, false: debug mode off)
   *
   * @example   $boolDebug = $objConfig->debug();
   *
   */
  public function debug() : bool {
    return $this->debug;
  }

  /**
   * This method returns the password rules
   * (min length, required character groups) for validation.
   *
   * @return    object   The password rules
   *
   * @example   $objPasswordRules = $objConfig->passwordRules();
   *
   */
  public function passwordRules() : object {
    return $this->passwordRules;
  }

}

// EOF
