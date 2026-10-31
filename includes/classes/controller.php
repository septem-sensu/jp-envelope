<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );
require_once ( __DIR__ . '/../classes/view.php' );
require_once ( __DIR__ . '/../classes/user.php' );
require_once ( __DIR__ . '/../classes/role.php' );
require_once ( __DIR__ . '/../classes/presentation.php' );
require_once ( __DIR__ . '/../classes/config.php' );
require_once ( __DIR__ . '/../classes/request.php' );
require_once ( __DIR__ . '/../classes/appError.php' );
require_once ( __DIR__ . '/../classes/app.php' );
require_once ( __DIR__ . '/../classes/diagnostics.php' );

/**
 * This class is the front controller of the app.
 * index.php instantiates it and calls execute(). The constructor resolves the
 * auth context (user, role), the view (views.json configuration with the
 * permission guard) and the request scope; execute() then runs the view
 * actions, collects the soft errors of the actions into the AppError channel,
 * throws the business objects into the frontend collector and returns the
 * rendered HTML or the json payload. Hard errors are caught granularly:
 * an action crash lands in the soft error channel with generic text,
 * a view crash leads to the emergency page with HTTP 500.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-10
 *
 * @example   $objController = new Controller();
 *            echo $objController->execute();
 *
 */
class Controller extends BaseObject {

  /**
   * The generic frontend text for hard errors. Deliberately generic
   * (information leak shielding) — the real exception details go
   * exclusively into the server log via logHardError().
   *
   * @var       string
   *
   */
  const HARD_ERROR_MESSAGE = 'Es ist ein interner Fehler aufgetreten.';

  /**
   * The emergency page for hard errors, inline instead of via
   * processTemplate(): the template system itself can be the crash cause.
   *
   * @var       string
   *
   */
  const HARD_ERROR_HTML    = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
    . '<title>JPEnvelope</title></head><body><h1>JPEnvelope</h1><p>' . self::HARD_ERROR_MESSAGE . '</p></body></html>';

  /**
   * The id of the requested business object (GET/POST parameter 'id').
   *
   * @var       string
   *
   */
  protected string       $objectId;

  /**
   * The class name of the requested business object (GET/POST parameter 'class').
   *
   * @var       string
   *
   */
  protected string       $objectClass;

  /**
   * The resolved view configuration (views.json).
   *
   * @var       View
   *
   */
  protected View         $viewObject;

  /**
   * The resolved user of the request (auth resolution order).
   *
   * @var       User
   *
   */
  protected User         $user;

  /**
   * The role of the resolved user.
   *
   * @var       Role
   *
   */
  protected Role         $role;

  /**
   * The presentation instance for template variables and rendering.
   *
   * @var       Presentation
   *
   */
  protected Presentation $presentation;

  /**
   * The frontend collector: raw object envelopes in insertion order.
   *
   * @var       array
   *
   */
  protected array        $arrRawObjects    = [];

  /**
   * The dedupe map of the collector: "class::id" to index
   * (last envelope wins, the position stays).
   *
   * @var       array
   *
   */
  protected array        $arrRawObjectKeys = [];

  /**
   * The collected soft errors (AppError instances) of the actions.
   *
   * @var       array
   *
   */
  protected array        $arrErrors        = [];

  /**
   * This method is the constructor of the class and boots the app context.
   * It delegates to init() for the auth, view and scope resolution.
   *
   * @return    void
   *
   * @example   $objController = new Controller();
   *
   */
  public function __construct() {
    parent::__construct();

    $this->init();

    return;
  }

  /**
   * This method resolves the request context. It creates the user (auth
   * resolution order), the presentation and the role, resolves the view
   * from the view parameter (a view the role has no permission for —
   * or an unknown view — silently falls back to the role's default
   * view) and sets the request scope to the view. The id/class parameters
   * are stored for the getters only — the business object resolution
   * happens in View::executeView().
   *
   * @return    void
   *
   * @example   $this->init();
   *
   */
  private function init() : void {
    $this->user         = new User();
    $this->presentation = new Presentation();
    $this->role         = $this->user->role();
    $strView            = Request::param( 'view' );
    $this->viewObject   = isset( $strView ) && $this::existsObject( 'View', $strView ) ? new View( $strView ) : new View( $this->role->defaultViewId() );
    $this->viewObject   = in_array( $this->role->id, $this->viewObject->roleIds() ) ? $this->viewObject : new View( $this->role->defaultViewId() );

    Request::setScope( $this->viewObject->id() );

    $strId              = Request::param( 'id' );
    $strClass           = Request::param( 'class' );

    if( isset( $strId ) ) $this->objectId       = $strId;
    if( isset( $strClass ) ) $this->objectClass = $strClass;

    return;
  }

  /**
   * This method runs the request: it throws the app context as the first
   * envelope (formerly the loader unshift: class file loading and
   * instantiation run in the loader in collector order — the app carries
   * the user and view context right away via currentUserId,
   * currentUserRoleId and currentView), executes the actions of the view
   * (a crash is caught and routed into the soft error channel as a
   * generic AppError, the details only into the log), throws the
   * collected soft errors and the current user into the collector and
   * finally executes the view itself. A view crash gets no addError
   * (the collector foreach has already run, it would be a zombie
   * without a display path): json views answer empty with HTTP
   * 500, HTML views answer with the emergency page.
   *
   * @return    mixed   The rendered HTML (string), the json payload (string) or '' on a json view crash
   *
   * @example   $strContent = $objController->execute();
   *
   */
  public function execute() : mixed {
    $this->presentation()->assignTemplateVar( 'default', 'rawObjects', [] );

    $this->addRawObject( new App( $this ) );

    try {
      $this->viewObject->executeActions( $this );
    } catch( Throwable $objException ) {
      $this->addErrorFromThrowable( $objException, 'executeActions' );
    }

    foreach( $this->arrErrors as $objError ) {
      $this->addRawObject( $objError );
    }

    $this->addRawObject( $this->user() );

    $strResultType = strtolower( $this->viewObject->resultType() );

    try {
      if( $strResultType == 'json' ) {
        $this->presentation()->getJsonHeader();

        return json_encode( $this->viewObject->executeView( $this ) );
      }

      return $this->viewObject->executeView( $this );
    } catch( Throwable $objException ) {
      self::logHardError( $objException, 'executeView' );
      http_response_code( 500 );

      return $strResultType == 'json' ? '' : self::HARD_ERROR_HTML;
    }
  }

  /**
   * This method adds a business object and its sub objects to the frontend collector
   * as a raw object envelope ({ class, id, data }). The collector dedupes over
   * "class::id": a repeated key overwrites the old envelope in place —
   * the last one wins, the position stays. The collector is injected
   * into the header template as window[appAlias].rawObjects.
   *
   * @param     BaseObject      $objObject         The business object to collect
   * @return    void
   *
   * @example   $objController->addRawObject( $objUser );
   *
   */
  public function addRawObject( BaseObject $objObject ) : void {
    $arrRawObjects = $objObject->rawObject( $this );

    foreach( $arrRawObjects as $objRawObject ) {
      $strObjectKey = $objRawObject->class . '::' . $objRawObject->id;

      if( isset( $this->arrRawObjectKeys[ $strObjectKey ] ) ) {
        $this->arrRawObjects[ $this->arrRawObjectKeys[ $strObjectKey ] ] = $objRawObject;
      } else {
        $this->arrRawObjectKeys[ $strObjectKey ] = count( $this->arrRawObjects );

        array_push( $this->arrRawObjects, $objRawObject );
      }
    }

    $this->presentation()->assignTemplateVar( 'default', 'rawObjects', $this->arrRawObjects );

    return;
  }

  /**
   * This method returns the frontend collector
   * (raw object envelopes in insertion order).
   *
   * @return    array   The collector envelopes
   *
   * @example   $arrRawObjects = $objController->rawObjects();
   *
   */
  public function rawObjects() : array {
    return $this->arrRawObjects;
  }

  /**
   * This method returns the collected soft errors (AppError instances)
   * of the actions.
   *
   * @return    array   The collected errors
   *
   * @example   $arrErrors = $objController->errors();
   *
   */
  public function errors() : array {
    return $this->arrErrors;
  }

  /**
   * This method collects a soft error: actions report rejected guards
   * here, and execute() throws the collected errors into the frontend
   * collector as AppError envelopes. The severity must be 'error' or
   * 'warning'. The view permission check deliberately stays silent —
   * missing permissions are not an information channel to the outside.
   *
   * @param     string        $strMessage    The error message for the frontend
   * @param     string|null   $strSource     The action trigger that rejected (e.g. 'changeUser')
   * @param     string        $strSeverity   The severity, 'error' (default) or 'warning'
   * @return    void
   *
   * @throws    RuntimeException   When the severity is unknown
   *
   * @example   $objController->addError( 'Ungültige Eingabe.', 'createUser' );
   *
   */
  public function addError( string $strMessage, string | null $strSource = null, string $strSeverity = 'error' ) : void {
    if( ! in_array( $strSeverity, [ 'error', 'warning' ] ) ) throw new RuntimeException( 'Unbekannte Severity: ' . $strSeverity );

    $objError = new AppError( 'error' . ( count( $this->arrErrors ) + 1 ) );
    $objError->setMessage( $strMessage, $strSource, $strSeverity );

    array_push( $this->arrErrors, $objError );

    return;
  }

  /**
   * This method collects an array of soft errors:actions report rejected guards
   * here, undexecute() throws the collected errors into the frontend
   * Collector as AppError envelopes. The severity must be “Error” or
   * 'Warning'. The view permission check remains intentionally silent –
   * Missing authorizations are not an information channel to the outside world.
   *
   * @param     array         $arrMessages   The array with error message for the frontend
   * @param     string|null   $strSource     The action trigger that rejected (e.g. 'changeUser')
   * @param     string        $strSeverity   The severity, 'error' (default) or 'warning'
   * @return    bool          Indicates whether errors were added to the collector
   *
   * @throws    RuntimeException   When the severity is unknown
   *
   * @example   $hasAdded = $objController->addErrors( [ 'Ungültige Eingabe.'. 'Bitte Name angeben.' }, 'createUser', 'warning' );
   *
   */
  public function addErrors( array $arrMessages, string | null $strSource = null, string $strSeverity = 'error' ) : bool {
    if( ! in_array( $strSeverity, [ 'error', 'warning' ] ) ) throw new RuntimeException( 'Unbekannte Severity: ' . $strSeverity );

    $hasAdded = false;

    foreach( $arrMessages as $strMessage ) {
      $hasAdded = true;

      $this->addError( $strMessage, $strSource, $strSeverity );
    }

    return $hasAdded;
  }

  /**
   * This method logs a hard error with its full details (class, message,
   * file:line, trace) into the server log. It is public static for a
   * reason: it is one formatting source with two entry points —
   * execute() and index.php — and index.php has no controller
   * instance in a boot crash.
   *
   * @param     Throwable    $objException   The caught exception
   * @param     string       $strSource       The source context (e.g. 'executeView')
   * @return    void
   *
   * @example   Controller::logHardError( $objException, 'index.php' );
   *
   */
  public static function logHardError( Throwable $objException, string $strSource ) : void {
    $strContext = get_class( $objException ) . ' [' . $strSource . ']: ' . $objException->getMessage()
      . ' @ ' . $objException->getFile() . ':' . $objException->getLine() . PHP_EOL
      . $objException->getTraceAsString();

    Presentation::logToFile( $strContext );

    return;
  }

  /**
   * This method converts an unhandled exception into a soft error:
   * the details go into the server log via logHardError(), the
   * frontend gets the generic hard error message as an AppError.
   *
   * @param     Throwable    $objException   The caught exception
   * @param     string       $strSource      The action trigger or context (default 'exception')
   * @return    void
   *
   * @example   $objController->addErrorFromThrowable( $objException, 'executeActions' );
   *
   */
  public function addErrorFromThrowable( Throwable $objException, string $strSource = 'exception' ) : void {
    self::logHardError( $objException, $strSource );
    $this->addError( self::HARD_ERROR_MESSAGE, $strSource, 'error' );

    return;
  }

  /**
   * This method returns the class name of the request object
   * (GET/POST parameter 'class') or null.
   *
   * @return    string|null   The class name or null
   *
   * @example   $strClass = $objController->objectClass();
   *
   */
  public function objectClass() : string | null {
    if( ! isset( $this->objectClass ) ) return null;

    return $this->objectClass;
  }

  /**
   * This method returns the id of the request object
   * (GET/POST parameter 'id') or null.
   *
   * @return    string|null   The object id or null
   *
   * @example   $strId = $objController->objectId();
   *
   */
  public function objectId() : string | null {
    if( ! isset( $this->objectId ) ) return null;

    return $this->objectId;
  }

  /**
   * This method returns the resolved view configuration.
   *
   * @return    View|null   The view instance or null
   *
   * @example   $objView = $objController->viewObject();
   *
   */
  public function viewObject() : View | null {
    if( ! isset( $this->viewObject ) ) return null;

    return $this->viewObject;
  }

  /**
   * This method returns the resolved user of the request.
   *
   * @return    User|null   The user instance or null
   *
   * @example   $objUser = $objController->user();
   *
   */
  public function user() : User | null {
    if( ! isset( $this->user ) ) return null;

    return $this->user;
  }

  /**
   * This method returns the role of the resolved user.
   *
   * @return    Role|null   The role instance or null
   *
   * @example   $objRole = $objController->role();
   *
   */
  public function role() : Role | null {
    if( ! isset( $this->role ) ) return null;

    return $this->role;
  }

  /**
   * This method returns the presentation instance of the request.
   *
   * @return    Presentation|null   The presentation instance or null
   *
   * @example   $objPresentation = $objController->presentation();
   *
   */
  public function presentation() : Presentation | null {
    if( ! isset( $this->presentation ) ) return null;

    return $this->presentation;
  }

}

// EOF
