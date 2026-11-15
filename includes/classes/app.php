<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/presentation.php' );

/**
 * This class is the app context envelope for the frontend.
 * Ephemeral like AppError, never persisted: a transport envelope for the
 * JS context access. The constructor takes currentUserId, currentUserRoleId,
 * the serialized currentView and the CSRF token from the controller —
 * header.start.tmpl needs no own template assignments for them
 * (window[appAlias].view/user disappeared there). The JS class app.js fills
 * registry, config and the fields itself in its constructor from the
 * namespace — fillObject() does not overwrite already set properties.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-20
 *
 * @example   $objApp = new App( $objController );
 *
 */
class App extends BaseObject {

  /**
   * The id of the resolved user of the request.
   *
   * @var       string
   *
   */
  protected string $currentUserId;

  /**
   * The role id of the resolved user of the request.
   *
   * @var       string
   *
   */
  protected string $currentUserRoleId;

  /**
   * The serialized view configuration of the request.
   *
   * @var       object
   *
   */
  protected object $currentView;

  /**
   * The CSRF token of the request: a stateless HMAC over the raw auth
   * cookie token (createCsrfToken), empty for guests. It rotates with
   * the auth cookie token (re-login, password change). The client
   * appends it to every POST request (fetch routes and native form
   * submissions) — the controller validates it against the recomputed
   * token (isCsrfViolation).
   *
   * @var       string
   *
   */
  protected string $csrfToken;

  /**
   * This method is the constructor of the class and takes over the request
   * context from the controller. The object id is the app alias plus the
   * app version from config.json (e.g. 'JPEnvelope_0.2.10') — a version
   * bump also moves the registry id of the app instance this way.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   $objApp = new App( $objController );
   *
   */
  public function __construct( Controller $objController ) {
    $objConfig               = BaseObject::config();

    $this->currentUserId     = $objController->user()->id();
    $this->currentUserRoleId = $objController->user()->roleId();
    $this->currentView       = $objController->viewObject()->serializeObject();
    $this->csrfToken         = BaseObject::createCsrfToken( Presentation::getCookieProperty( 'token' ) ?? '' );

    parent::__construct( $objConfig->appAlias() . '_' . $objConfig->version() );

    return;
  }

}

// EOF
