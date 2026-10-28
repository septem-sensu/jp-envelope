<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/baseObject.php' );

/**
 * This class represents a configured view (views.json): it binds a view
 * method to a business class, carries the result type (empty = HTML,
 * 'json' = JSON response), the template chain and the allowed role ids,
 * and executes the actions and the view method of the view.
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-10
 *
 * @example   $objView = new View( 'viewUserList' );
 *
 */
class View extends BaseObject {

  /**
   * The name of the view method on the business class.
   *
   * @var       string
   *
   */
  protected string $viewName;

  /**
   * The name of the business class the view method lives on.
   *
   * @var       string
   *
   */
  protected string $className;

  /**
   * The result type of the view: empty for HTML output, 'json' for
   * a JSON response with json headers.
   *
   * @var       string
   *
   */
  protected string $resultType;

  /**
   * The template chain of the view — processed in this order,
   * the output is concatenated. Device templates must stand before
   * their consuming view template (e.g. user.list.user.tmpl
   * before user.list.tmpl).
   *
   * @var       array
   *
   */
  protected array  $templateNames;

  /**
   * The ids of the roles that are allowed to see the view.
   *
   * @var       array
   *
   */
  protected array  $roleIds;

  /**
   * The action entries of the view, executed in this order before the
   * view rendering. Each entry is an object with a "method" field
   * ("Class::method") and exactly one routing field: "trigger"
   * (string) for request-triggered actions that run only when the
   * request's 'action' parameter matches the trigger, or "always"
   * (true) for actions that run on every request of the view.
   * Filtered from the frontend payload via the NOSERIALIZE constant.
   *
   * @var       array
   *
   */
  protected array  $actionNames;

  /**
   * This method returns the name of the view method.
   *
   * @return    string|null   The view method name or null when not set
   *
   * @example   $strViewName = $objView->viewName();
   *
   */
  public function viewName() : string | null {
    if( ! isset( $this->viewName ) ) return null;

    return $this->viewName;
  }

  /**
   * This method returns the result type of the view
   * (empty string for HTML, 'json' for JSON responses).
   *
   * @return    string|null   The result type or null when not set
   *
   * @example   $strResultType = $objView->resultType();
   *
   */
  public function resultType() : string | null {
    if( ! isset( $this->resultType ) ) return null;

    return $this->resultType;
  }

  /**
   * This method returns the template chain of the view.
   *
   * @return    array|null   The template names in processing order or null
   *
   * @example   $arrTemplateNames = $objView->templateNames();
   *
   */
  public function templateNames() : array | null {
    if( ! isset( $this->templateNames ) ) return null;

    return $this->templateNames;
  }

  /**
   * This method returns the ids of the roles allowed to see the view.
   *
   * @return    array|null   The role ids or null when not set
   *
   * @example   $arrRoleIds = $objView->roleIds();
   *
   */
  public function roleIds() : array | null {
    if( ! isset( $this->roleIds ) ) return null;

    return $this->roleIds;
  }

  /**
   * This method returns the action names of the view.
   *
   * @return    array|null   The action names or null when not set
   *
   * @example   $arrActionNames = $objView->actionNames();
   *
   */
  public function actionNames() : array | null {
    if( ! isset( $this->actionNames ) ) return null;

    return $this->actionNames;
  }

  /**
   * This method routes the request action centrally: triggered
   * actions run only when the request's 'action' parameter matches
   * their configured trigger, always actions run on every request of
   * the view — executed in their configured order before the view
   * rendering. Both config sources are validated fail-loud against
   * the class code (config drift fails loudly here instead of dying
   * silently, see validateActions/validatePermissions). Actions the
   * role has no permission for are skipped — but when the request
   * triggers exactly such an action, the user gets a visible
   * AppError instead of silent nothing, and an 'action' parameter
   * matching no trigger of the view gets a visible unknown action
   * error. Actions run as static methods with the controller as
   * parameter.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    void
   *
   * @example   $objView->executeActions( $objController );
   *
   */
  public function executeActions( Controller $objController ) : void {
    $arrPermissions = $objController->role()->permissions();

    $this->validateActions( $this->actionNames );
    $this->validatePermissions( $arrPermissions );

    $strActionTrigger = Request::param( 'action' );
    $boolMatched      = false;

    foreach( $this->actionNames as $objAction ) {
      $strMethod      = $objAction->method;
      $boolAlways     = ( $objAction->always ?? false ) === true;
      $boolTriggered  = ! $boolAlways && isset( $strActionTrigger ) && ( $objAction->trigger ?? null ) === $strActionTrigger;

      if( $boolTriggered ) $boolMatched = true;

      if( ! in_array( $strMethod, $arrPermissions ) ) {
        if( $boolTriggered ) $objController->addError( 'Keine Berechtigung für diese Aktion.', $objAction->trigger );

        continue;
      }

      if( $boolAlways || $boolTriggered ) $strMethod( $objController );
    }

    if( isset( $strActionTrigger ) && ! $boolMatched ) $objController->addError( 'Unbekannte Aktion.', $strActionTrigger );

    return;
  }

  /**
   * This method validates the action entries of the view (objects
   * with a "method" field plus exactly one routing field, see the
   * actionNames property) fail-loud: the entry must be an object
   * with a valid "Class::method" that exists in the class code, and
   * either a string "trigger" or a true "always" — neither both
   * nor none. Config drift (a renamed or removed method, an
   * ambiguous routing declaration) would otherwise die silently.
   * The RuntimeException lands via the executeActions catch of
   * execute() in the soft error channel + server log.
   *
   * @param     array     $arrActions     The action entries of the view
   * @return    void
   *
   * @example   $this->validateActions( $this->actionNames );
   *
   */
  private function validateActions( array $arrActions ) : void {
    $strPrefix = 'Die View ' . $this->id() . ' konfiguriert ';

    foreach( $arrActions as $objAction ) {
      if( ! is_object( $objAction ) ) throw new RuntimeException( $strPrefix . 'einen ungültigen Action-Eintrag (Objekt erwartet): ' . gettype( $objAction ) );
      if( ! isset( $objAction->method ) || ! is_string( $objAction->method ) ) throw new RuntimeException( $strPrefix . 'einen Action-Eintrag ohne gültige Methode.' );

      $arrMethodParts = explode( '::', $objAction->method );

      if( count( $arrMethodParts ) !== 2 || ! class_exists( $arrMethodParts[ 0 ] ) || ! method_exists( $arrMethodParts[ 0 ], $arrMethodParts[ 1 ] ) ) throw new RuntimeException( $strPrefix . 'eine unbekannte Action: ' . $objAction->method );

      $boolHasTrigger = isset( $objAction->trigger );
      $boolHasAlways  = isset( $objAction->always );

      if( $boolHasTrigger && $boolHasAlways ) throw new RuntimeException( $strPrefix . 'eine Action mit trigger und gleichzeitig always: ' . $objAction->method );
      if( ! $boolHasTrigger && ! $boolHasAlways ) throw new RuntimeException( $strPrefix . 'eine Action ohne trigger und ohne always: ' . $objAction->method );
      if( $boolHasTrigger && ! is_string( $objAction->trigger ) ) throw new RuntimeException( $strPrefix . 'eine Action mit ungültigem trigger (String erwartet): ' . $objAction->method );
      if( $boolHasAlways && $objAction->always !== true ) throw new RuntimeException( $strPrefix . 'eine Action mit ungültigem always (true erwartet): ' . $objAction->method );
    }

    return;
  }

  /**
   * This method validates the permission entries of the role
   * ("Class::method") fail-loud against the class code: the class
   * and the method must exist, the entry must consist of exactly
   * two segments. Config drift (a renamed or removed method) would
   * otherwise die silently — a permission that no action ever
   * matches again. The RuntimeException lands via the executeActions
   * catch of execute() in the soft error channel + server log.
   *
   * @param     array     $arrPermissions     The permission entries of the role
   * @return    void
   *
   * @example   $this->validatePermissions( $objController->role()->permissions() );
   *
   */
  private function validatePermissions( array $arrPermissions ) : void {
    foreach( $arrPermissions as $strPermission ) {
      $arrPermissionParts = explode( '::', $strPermission );

      if( count( $arrPermissionParts ) !== 2 || ! class_exists( $arrPermissionParts[ 0 ] ) || ! method_exists( $arrPermissionParts[ 0 ], $arrPermissionParts[ 1 ] ) ) {
        throw new RuntimeException( 'Die Rolle konfiguriert eine Permission ohne existierende Methode: ' . $strPermission );
      }
    }

    return;
  }

  /**
   * This method executes the view itself: it resolves the business
   * object (new instance without id or instance with the request id
   * over the controller getter) and calls the view method on it with
   * the controller as parameter. The return value is the rendered
   * HTML (string) or the json payload (object).
   *
   * @param     Controller          $objController   The controller instance of the request
   * @return    string|object       The view output (HTML) or the json payload
   *
   * @example   $mixContent = $objView->executeView( $objController );
   *
   */
  public function executeView( Controller $objController ) : string | object {
    $strObjectId = $objController->objectId();
    $objObject   = isset( $strObjectId ) ? new $this->className( $strObjectId ) : new $this->className();

    return $objObject->{ $this->viewName }( $objController );
  }

}

// EOF
