<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/fieldValidator.php' );
require_once ( __DIR__ . '/../classes/request.php' );

/**
 * This class represents a user account with its authentication and user management actions.
 * The constructor resolves the user following the auth resolution order: explicit id
 * (GET/POST parameter id), then the AES-encrypted cookie token, then the login
 * (user + password, SHA256 comparison) and finally the defaultUserId (guest) from
 * configs.json. All user list actions run as viewUserList actions and guard with the
 * 'action' parameter trigger.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
 * @since     2026-10-10
 *
 * @example   $objUser = new User( 'user@example.com' );
 *
 */
class User extends BaseObject {

  /**
   * Property names that are filtered from the frontend payload.
   * Declared explicitly instead of a union with parent::NOSERIALIZE:
   * array union with + would lose the parent entries on numeric keys.
   *
   * @var       array
   *
   */
  const NOSERIALIZE = [ "password", "dataJsonFile", "actionNames" ];

  /**
   * Property names that are filtered from persistence — unlike the
   * base class, the runtime flag isUserSet is excluded here too.
   *
   * @var       array
   *
   */
  const NOSAVE      = [ "dataJsonFile", "fields", "isUserSet" ];

  /**
   * The SHA256 hash of the user password (never serialized to the frontend).
   *
   * @var       string
   *
   */
  protected string $password;

  /**
   * The id of the role of the user.
   *
   * @var       string
   *
   */
  protected string $roleId;

  /**
   * The runtime flag of the resolution: true when the constructor
   * resolved a real user instead of falling back to the guest.
   *
   * @var       boolean
   *
   */
  protected bool   $isUserSet;

  /**
   * This method is the constructor of the class and resolves the user
   * following the auth resolution order (explicit id, cookie, login,
   * default guest user). The parent constructor with the resolved id
   * fills the account data; without a resolved user only the guest
   * is loaded.
   *
   * @param     string|null   $strId   The user id or null to resolve via auth order
   * @return    void
   *
   * @example   $objUser = new User( 'user@example.com' );
   *
   */
  public function __construct( string | null $strId = null ) {
    $this->isUserSet = false;
    $this->getUser( $strId );

    if( $this->isUserSet ) parent::__construct( $this->id );

    return;
  }

  /**
   * This method returns the SHA256 password hash of the user.
   *
   * @return    string|null   The password hash or null when not resolved
   *
   * @example   $strPassword = $objUser->password();
   *
   */
  public function password() : string | null {
    if( ! isset( $this->password ) ) return null;

    return $this->password;
  }

  /**
   * This method returns the id of the role of the user.
   *
   * @return    string|null   The role id or null when not resolved
   *
   * @example   $strRoleId = $objUser->roleId();
   *
   */
  public function roleId() : string | null {
    if( ! isset( $this->roleId ) ) return null;

    return $this->roleId;
  }

  /**
   * This method returns the role instance of the user.
   *
   * @return    Role|null   The role instance or null when not resolved
   *
   * @example   $objRole = $objUser->role();
   *
   */
  public function role() : Role | null {
    if( ! isset( $this->roleId ) ) return null;

    return new Role( $this->roleId );
  }

  /**
   * This method resolves the user following the auth resolution order:
   * explicit id, then cookie token, then login parameters, then the
   * default guest user from configs.json. The first hit wins and sets
   * isUserSet.
   *
   * @param     string|null   $strId   The explicit user id or null
   * @return    void
   *
   * @example   $this->getUser( $strId );
   *
   */
  private function getUser( string | null $strId = null ) : void {
    if( ! $this->isUserSet && isset( $strId ) ) $this->getUserFromId( $strId );
    if( ! $this->isUserSet ) $this->getUserFromCookie();
    if( ! $this->isUserSet ) $this->getUserFromLogin();
    if( ! $this->isUserSet ) $this->getUserFromId( BaseObject::config()->defaultUserId() );

    return;
  }

  /**
   * This method resolves the user from an explicit id.
   * Unknown ids are skipped silently.
   *
   * @param     string    $strId   The user id to resolve
   * @return    void
   *
   * @example   $this->getUserFromId( 'user@example.com' );
   *
   */
  private function getUserFromId( string $strId ) : void {
    if( ! isset( $strId ) ) return;
    if( ! BaseObject::existsObject( 'User', $strId ) ) return;

    $this->id        = $strId;
    $this->isUserSet = true;

    return;
  }

  /**
   * This action collects all user instances into the frontend collector
   * (collector envelopes) for the user list view.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::collectUsers( $objController );
   *
   */
  public static function collectUsers( Controller $objController ) : void {
    $arrUser = BaseObject::getObjectsFromClass( 'User' );

    for( $i = 0; $i < count( $arrUser ); $i++ ) {
      $objController->addRawObject( $arrUser[ $i ] );
    }

    return;
  }

  /**
   * This action logs the user out: it deletes the cookie and redirects
   * (HTTP 303) to the default view of the guest role. The script exits
   * after the redirect header.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::logout( $objController );
   *
   */
  public static function logout( Controller $objController ) : void {
    $objRole = BaseObject::getObject( 'Role', BaseObject::config()->defaultRoleId() );

    Presentation::deleteCookie();
    header( 'Location: index.php?view=' . $objRole->defaultViewId(), true, 303 );

    exit;
  }

  /**
   * This action creates a new user account from the user list form.
   * The viewUserList routes this action on the 'createUser' trigger
   * (see views.json), the central routing lives in View::executeActions.
   * All field rules (mandatory, password companion and security, select
   * options) run centralized in FieldValidator first — every failed field
   * turns visible at once; the action itself keeps only the domain guards:
   * the user id must still be free — an occupied id is never overwritten —
   * and the role must exist (second line behind the field options). The
   * account is persisted directly via insertObject instead of the
   * constructor: getUser() falls back to the guest user when the user does
   * not exist, so the User constructor can never deliver an empty new
   * instance. Rejected guards collect soft errors for the AppError channel.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::createUser( $objController );
   *
   */
  public static function createUser( Controller $objController ) : void {
    if( $objController->addErrors( FieldValidator::validate( 'User' ), 'createUser' ) ) return;

    $strId       = Request::param( 'id' );
    $strPassword = Request::param( 'password' );
    $strRoleId   = Request::param( 'roleId' );

    if( BaseObject::existsObject( 'User', $strId ) ) {
      $objController->addError( 'Unter der E-Mail-Adresse existiert bereits ein Account.', 'createUser' );

      return;
    }

    if( ! BaseObject::existsObject( 'Role', $strRoleId ) ) {
      $objController->addError( 'Die ausgewählte Rolle ist ungültig.', 'createUser' );

      return;
    }

    $objEntry           = new stdClass();
    $objEntry->id       = $strId;
    $objEntry->class    = 'User';
    $objEntry->password = BaseObject::enCrypteOnly( $strPassword );
    $objEntry->roleId   = $strRoleId;

    BaseObject::insertObject( 'User', $strId, $objEntry );

    return;
  }

  /**
   * This action saves changes to an existing user (password and/or role)
   * from the user list form. The viewUserList routes this action on the
   * 'changeUser' trigger (see views.json), the central routing lives in
   * View::executeActions. The field rules of both fields run centralized
   * in FieldValidator beforehand (the fieldset downgrades both to
   * optional: absent means unchanged); the action itself keeps the
   * domain guards. Password problems are reported individually: the rest
   * of the save (e.g. the role) deliberately continues. Role guard: an
   * administrator may change foreign roles, the own one stays protected;
   * the administrator check itself stays silent — missing permissions
   * are not an information channel to the outside.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::saveUser( $objController );
   *
   */
  public static function saveUser( Controller $objController ) : void {
    $strId         = Request::param( 'id' );
    $strPassword   = Request::param( 'password' );
    $strPassword2  = Request::param( 'password2' );
    $strRoleId     = Request::param( 'roleId' );

    if( ! isset( $strId ) || ! BaseObject::existsObject( 'User', $strId ) ) {
      $objController->addError( 'Der zu ändernde User existiert nicht.', 'changeUser' );

      return;
    }

    $arrFieldErrors = FieldValidator::validate( 'User', [ 'password' => false, 'roleId' => false ] );
    $objUser        = new User( $strId );

    if( isset( $strPassword ) || isset( $strPassword2 ) ) {
      if( $strId === $objController->user()->id() || $objController->role()->id() === 'administrator' ) {
        if( isset( $arrFieldErrors[ 'password' ] ) ) {
          $objController->addError( $arrFieldErrors[ 'password' ], 'changeUser' );
        } else {
          $objUser->password = BaseObject::enCrypteOnly( $strPassword );
        }
      } else {
        $objController->addError( 'Es kann nur das eigene Passwort geändert werden.', 'changeUser' );
      }
    }

    if( isset( $strRoleId ) ) {
      if( isset( $arrFieldErrors[ 'roleId' ] ) ) {
        $objController->addError( $arrFieldErrors[ 'roleId' ], 'changeUser' );
      } elseif( ! BaseObject::existsObject( 'Role', $strRoleId ) ) {
        $objController->addError( 'Die ausgewählte Rolle ist ungültig.', 'changeUser' );
      } elseif( $objController->role()->id() === 'administrator' ) {
        if( $strId === $objController->user()->id() ) {
          $objController->addError( 'Die eigene Rolle kann nicht geändert werden.', 'changeUser', 'warning' );
        } else {
          $objUser->roleId = $strRoleId;
        }
      }
    }

    $objUser->saveObject();

    return;
  }

  /**
   * This action changes the password of the logged-in user from the
   * user list form. The viewUserList routes this action on the
   * 'changeUserPassword' trigger (see views.json), the central routing
   * lives in View::executeActions. Only the own password can be changed;
   * the field rules (password companion, match, security) run centralized
   * in FieldValidator behind the domain guards. Password rules come from
   * configs.json.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::saveUserPassword( $objController );
   *
   */
  public static function saveUserPassword( Controller $objController ) : void {
    $strId           = Request::param( 'id' );
    $arrFieldErrors  = FieldValidator::validate( 'User', [ 'id', 'password' ] );

    if( $objController->addErrors( $arrFieldErrors, 'changeUserPassword' ) ) return;

    if( ! isset( $strId ) || ! BaseObject::existsObject( 'User', $strId ) ) {
      $objController->addError( 'Der User existiert nicht.', 'changeUserPassword' );

      return;
    }

    if( $strId !== $objController->user()->id() ) {
      $objController->addError( 'Es kann nur das eigene Passwort geändert werden.', 'changeUserPassword' );

      return;
    }

    $strPassword       = Request::param( 'password' );
    $objUser           = new User( $strId );
    $objUser->password = BaseObject::enCrypteOnly( $strPassword );

    $objUser->saveObject();

    return;
  }

  /**
   * This action deletes an existing user account from the user list form.
   * The viewUserList routes this action on the 'deleteUser' trigger
   * (see views.json), the central routing lives in View::executeActions.
   * Self-deletion guard: the logged-in user must not delete their own account.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   User::deleteUser( $objController );
   *
   */
  public static function deleteUser( Controller $objController ) : void {
    $strId = Request::param( 'id' );

    if( ! isset( $strId ) || ! BaseObject::existsObject( 'User', $strId ) ) {
      $objController->addError( 'Der zu löschende User existiert nicht.', 'deleteUser' );

      return;
    }

    if( $strId === $objController->user()->id() ) {
      $objController->addError( 'Der eigene Account kann nicht gelöscht werden.', 'deleteUser' );

      return;
    }

    $objUser = new User( $strId );

    $objUser->deleteObject();

    return;
  }

  /**
   * This method resolves the user from login parameters and writes the
   * cookie token on success. The explicit id (login form, POST) takes
   * precedence over the user name resolution order as
   * documented in the auth flow. The password is compared with
   * hash_equals against the SHA256 hash.
   *
   * @return    void
   *
   * @example   $this->getUserFromLogin();
   *
   */
  private function getUserFromLogin() : void {
    $strId       = Request::param( 'id' );
    $strId       = ! isset( $strId ) ? Request::param( 'user' ) : $strId;
    $strPassword = Request::param( 'password' );

    if( ! isset( $strId ) || ! isset( $strPassword ) ) return;
    if( ! BaseObject::existsObject( 'User', $strId ) ) return;

    $objUser = new User( $strId );

    if( ! hash_equals( $objUser->password(), BaseObject::enCrypteOnly( $strPassword ) ) ) return;

    $objParams        = new stdClass();
    $objParams->token = BaseObject::enCrypte( $strId . '|||' . BaseObject::enCrypteOnly( $strPassword ) );

    Presentation::writeCookie( $objParams );
    $this->getUserFromId( $strId );

    return;
  }

  /**
   * This method resolves the user from the encrypted cookie token.
   * The token is decrypted (AES) and carries the user id and the
   * password hash, separated by '|||'. Both must match an existing
   * user (hash_equals); otherwise the resolution is skipped silently.
   *
   * @return    void
   *
   * @example   $this->getUserFromCookie();
   *
   */
  private function getUserFromCookie() {
    $strCookieName  = BaseObject::config()->cookieName();

    if( ! isset( $_COOKIE ) || ! isset( $_COOKIE[ $strCookieName ] ) ) return;

    $objCookie             = json_decode( $_COOKIE[ $strCookieName ] );
    $strToken              = BaseObject::deCrypte( $objCookie->token );
    $arrToken              = explode( '|||', html_entity_decode( $strToken ) );
    $strPlayerIdFromCookie = $arrToken[0];
    $strPasswordFromCookie = $arrToken[1];

    if( ! BaseObject::existsObject( 'User', $strPlayerIdFromCookie ) ) return;

    $objUser = new User( $strPlayerIdFromCookie );

    if( ! isset( $strPasswordFromCookie ) || ! hash_equals( $objUser->password(), $strPasswordFromCookie ) ) return;

    $this->getUserFromId( $strPlayerIdFromCookie );

    return;
  }

}

// EOF
