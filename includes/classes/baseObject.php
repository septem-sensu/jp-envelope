<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/config.php' );
require_once ( __DIR__ . '/../classes/appError.php' );
require_once ( __DIR__ . '/../classes/fieldValidator.php' );

/**
 * This class is the base class of all business objects and provides their persistence.
 * The object state is serialized into includes/json/data/{className}.json (key = object id);
 * the constructor fills the instance properties automatically from that file via fillObject().
 * Technical properties are filtered by the two filter constants: NOSERIALIZE removes properties
 * from the frontend payload, NOSAVE removes properties from persistence (both defined per subclass).
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.19
 * @since     2026-10-10
 *
 * @example   $objUser = new User( 'someUserId' );
 *
 */
class BaseObject {

  /**
   * Base file path of the includes directory (relative to this class file).
   *
   * @var       string
   *
   */
  const FILEPATHBASE      = __DIR__ . '/../';

  /**
   * File path of the json configuration directory (params.json, role.json, etc.).
   *
   * @var       string
   *
   */
  const FILEPATHJSON      = __DIR__ . '/../json/';

  /**
   * File path of the form field definitions ({className}.json per business class).
   *
   * @var       string
   *
   */
  const FILEPATHFIELDS    = __DIR__ . '/../json/fields/';

  /**
   * File path of the template directory.
   *
   * @var       string
   *
   */
  const FILEPATHTEMPLATES = __DIR__ . '/../templates/';

  /**
   * The timeout in seconds when acquiring the exclusive file lock of a
   * class json file (withFileLock). Exceeded, a RuntimeException is
   * thrown — fail loud instead of an endless wait.
   *
   * @var       float
   *
   */
  const LOCKTIMEOUT       = 5.0;

  /**
   * The sleep time in microseconds between non-blocking lock retries
   * (withFileLock).
   *
   * @var       int
   *
   */
  const LOCKSLEEP         = 50000;

  /**
   * The blueprint id of the generic json routes (viewNewObject carries it
   * in the response envelope, viewSaveObject treats it as the create
   * trigger and replaces it with a generated id) — the same contract
   * value the client Registry.BLUEPRINTID holds.
   *
   * @var       string
   *
   */
  const BLUEPRINTID       = 'new';

  /**
   * The running number for the ids of the AppError envelopes the generic
   * json routes (viewSaveObject, viewDeleteObject) return on rejection —
   * every failed response gets unique display ids without a controller
   * error counter.
   *
   * @var       int
   *
   */
  static int $intErrorEnvelopeCount = 0;

  /**
   * Property names that are filtered from the frontend payload (rawObject / serializeObject),
   * e.g. the password hash of a user. Objects and arrays are json encoded by the Presentation layer.
   *
   * @var       array
   *
   */
  const NOSERIALIZE       = [ "dataJsonFile", "actionNames" ];

  /**
   * Property names that are filtered from persistence (saveObject),
   * e.g. technical runtime properties like the loaded form fields.
   *
   * @var       array
   *
   */
  const NOSAVE            = [ "dataJsonFile", "fields" ];

  /**
   * The unique object id (key within the class json file).
   *
   * @var       string
   *
   */
  protected string  $id;

  /**
   * The class name of the instance (set automatically in initObject()).
   *
   * @var       string
   *
   */
  protected string  $class;

  /**
   * The file path of the class json file the object persists into.
   *
   * @var       string
   *
   */
  protected string  $dataJsonFile;

  /**
   * The form field definitions loaded from FILEPATHFIELDS,
   * empty stdClass when the class has no field file.
   *
   * @var       object
   *
   */
  protected object  $fields;

  /**
   * The id of the user who created the object — set by the generic save
   * route (viewSaveObject) and the user create action on a create. The
   * owner is a permission subject like the role ids: the classPermissions
   * map of the view accepts the pseudo role 'owner' next to real role
   * ids. Deliberately not filtered by NOSERIALIZE (travels to the
   * frontend for UI visibility decisions) nor NOSAVE (persists). The
   * property is not a declared field, so a client can never deliver or
   * overwrite it over the save envelope — ownership changes stay a
   * server-side task.
   *
   * @var       string
   *
   */
  protected string  $ownerId;

  /**
   * This method is the constructor of the class and initializes the object.
   * Without an id parameter a new object id is generated via newId().
   *
   * @param     string|null   $strObjectId   The object id or null to generate a new one
   * @return    void
   *
   * @example   $objBaseObject = new BaseObject( 'myObjectId' );
   *
   */
  public function __construct( string | null $strObjectId = null ) {
    $this->initObject( $strObjectId );

    return;
  }

  /**
   * This method initializes the instance: class name, id, form fields and the persistence
   * file path, followed by fillObject(). It exists as a separate method because
   * createBlueprint() initializes instances past the constructor (subclass constructors
   * resolve their own fallbacks, e.g. User falls back to the guest user) and needs
   * the same source.
   *
   * @param     string|null   $strObjectId   The object id or null to generate a new one
   * @return    void
   *
   * @example   $this->initObject( $strObjectId );
   *
   */
  protected function initObject( string | null $strObjectId = null ) : void {
    $this->class        = get_class( $this );
    $this->id           = isset( $strObjectId ) ? $strObjectId : $this->newId( $this->class );
    $this->fields       = BaseObject::getFieldsOfClass( $this->class );
    $this->dataJsonFile = $this::FILEPATHJSON . 'data/' . lcfirst( $this->class ) . '.json';

    if( $this->fillObject() ) $this->onLoad();

    return;
  }

  /**
   * This method is the default getter of the class.
   * It returns null when the property does not exist on the class or is not set.
   *
   * @param     string    $strProperty   The name of the property
   * @return    mixed     The value of the property or null
   *
   * @example   $strId = $objBaseObject->get( 'id' );
   *
   */
  public function get( string $strProperty ) {
    if( ! property_exists( $this->class, $strProperty ) ) return null;
    if( ! isset( $this->$strProperty ) ) return null;

    return $this->$strProperty;
  }

  /**
   * This method returns the unique object id.
   *
   * @return    string   The object id
   *
   * @example   $strId = $objBaseObject->id();
   *
   */
  public function id() : string {
    return $this->id;
  }

  /**
   * This method returns the class name of the instance.
   *
   * @return    string   The class name
   *
   * @example   $strClass = $objBaseObject->className();
   *
   */
  public function className() : string {
    return $this->class;
  }

  /**
   * This method returns the id of the user who created the object.
   *
   * @return    string|null   The owner id or null when not set (blueprints
   *                          and entries persisted before the owner concept)
   *
   * @example   $strOwnerId = $objBaseObject->ownerId();
   *
   */
  public function ownerId() : string | null {
    if( ! isset( $this->ownerId ) ) return null;

    return $this->ownerId;
  }

  /**
   * This method returns the form field definitions of the class.
   *
   * @return    object   The field definitions (empty stdClass when the class has none)
   *
   * @example   $objFields = $objBaseObject->fields();
   *
   */
  public function fields() : object {
    return $this->fields;
  }

  /**
   * This method is the lifecycle hook of the generic save route
   * (viewSaveObject). Business classes override it to enforce ownership
   * and domain guards before the merged object persists. Returning a
   * string aborts the save — the message travels to the frontend as an
   * AppError envelope; returning null allows the save. The controller
   * follows the request context of the save (a null value means the
   * save runs outside a request, e.g. in tests).
   *
   * @param     Controller|null   $objController   The controller instance of the request or null
   * @return    string|null       The rejection message or null to allow the save
   *
   * @example   public function onSave( ?Controller $objController = null ) : ?string { ... }
   *
   */
  public function onSave( ?Controller $objController = null ) : ?string {
    return null;
  }

  /**
   * This method is the lifecycle hook of the generic delete route
   * (viewDeleteObject). Business classes override it to enforce ownership
   * and domain guards and to prepare cascades before the entry is removed.
   * Returning a string aborts the delete — the message travels to the
   * frontend as an AppError envelope; returning null allows the delete.
   * The controller follows the request context of the delete (a null
   * value means the delete runs outside a request, e.g. in tests).
   *
   * @param     Controller|null   $objController   The controller instance of the request or null
   * @return    string|null       The rejection message or null to allow the delete
   *
   * @example   public function onDelete( ?Controller $objController = null ) : ?string { ... }
   *
   */
  public function onDelete( ?Controller $objController = null ) : ?string {
    return null;
  }

  /**
   * This method is the lifecycle hook of object instantiation. It fires
   * only when initObject() actually loaded a persisted entry (fillObject()
   * returned true) — blueprints and instantiations with an unknown or
   * empty id stay hook-less. Business classes override it to derive state
   * from the loaded entry, warm caches or log on load; the instance itself
   * ($this) carries the full context and there is no return protocol —
   * the framework ignores anything a subclass returns. Unlike onSave and
   * onDelete the hook also runs outside a request context (e.g. when
   * references instantiate their targets during fillObject()).
   *
   * @return    void
   *
   * @example   public function onLoad() : void { ... }
   *
   */
  public function onLoad() : void {
    return;
  }

  /**
   * This method loads the form field definitions of a class from the
   * field file in FILEPATHFIELDS. When the file does not exist, an
   * empty stdClass is returned. It is the static read route next to
   * the instance access: the FieldValidator reads through it, and
   * initObject() fills the fields property through it.
   *
   * @param     string    $strClassName   The name of the business class
   * @return    object    The field definitions or an empty stdClass
   *
   * @example   $objFields = BaseObject::getFieldsOfClass( 'User' );
   *
   */
  public static function getFieldsOfClass( string $strClassName ) : object {
    $strFieldFile = BaseObject::FILEPATHFIELDS . lcfirst( $strClassName ) . '.json';

    if( ! file_exists( $strFieldFile ) ) return new stdClass();

    return BaseObject::loadFileDeCrypted( $strFieldFile );
  }

  /**
   * This method creates a new unique object id (uniqid with class prefix
   * and additional entropy).
   *
   * @param     string    $strPrefix   The prefix of the id (usually the class name)
   * @return    string    The new unique id
   *
   * @example   $strId = $objBaseObject->newId( 'User' );
   *
   */
  public function newId( string $strPrefix ) : string {
    return uniqid( $strPrefix . '_', true );
  }

  /**
   * This method creates a blueprint instance for the frontend (app.newObject /
   * viewNewObject route): empty and unsaved, but with the full field definitions.
   * It uses reflection instead of the constructor because subclass constructors
   * have their own resolution logic (User falls back to the guest user without
   * an id) — an empty instance could never be created through the constructor
   * route.
   *
   * @param     string       $strClassName   The name of the business class
   * @return    BaseObject   The blueprint instance with the blueprint id
   *
   * @example   $objBlueprint = BaseObject::createBlueprint( 'User' );
   *
   */
  public static function createBlueprint( string $strClassName ) : BaseObject {
    $objBlueprint = ( new ReflectionClass( $strClassName ) )->newInstanceWithoutConstructor();
    $objBlueprint->initObject( self::BLUEPRINTID );

    return $objBlueprint;
  }

  /**
   * This method checks whether a string is valid JSON.
   *
   * @param     string    $strContent   The string to check
   * @return    boolean   True when the string is valid JSON, false otherwise
   *
   * @example   $boolIsJson = BaseObject::isJson( $strContent );
   *
   */
  public static function isJson( string $strContent ) : bool {
    json_decode( $strContent );

    return json_last_error() === JSON_ERROR_NONE ? true : false;
  }

  /**
   * This method fills the instance properties from the persisted entry
   * of the object in the class json file. It reads the complete stored
   * state: builtin properties are set directly, 'class' references are
   * instantiated from the stored id and 'collection' properties are
   * rebuilt from the stored id list — already set instance properties
   * are overwritten (consistent reload semantics). Values that are
   * missing or empty in the file leave the corresponding property
   * untouched, properties that do not exist on the class are skipped.
   * It is a no-op when the id, the class name or the file is missing —
   * the boolean result reports whether an entry was actually loaded,
   * so callers can react (e.g. initObject() fires the onLoad hook).
   *
   * @return    boolean   True when the persisted entry was loaded, false on no-op
   *
   * @example   $boolLoaded = $objBaseObject->fillObject();
   *
   */
  public function fillObject() : bool {
    if( ! isset( $this->id ) || $this->id == '' ) return false;
    if( ! isset( $this->class ) || $this->class == '' ) return false;
    if( ! file_exists( $this->dataJsonFile ) ) return false;

    $objRawObject = self::getRawObject( $this->class, $this->id );

    if( ! isset( $objRawObject ) ) return false;

    foreach( $objRawObject as $strProperty => $mixValue ) {
      if( ! property_exists( $this->class, $strProperty ) ) continue;

      $strPropertyType = self::getPropertyType( $this->class, $strProperty );

      if( $strPropertyType === 'builtin' ) {
        $this->$strProperty = $mixValue;

        continue;
      }

      if( ! isset( $mixValue ) || $mixValue === '' ) continue;

      if( $strPropertyType === 'class' ) {
        $strClassName       = self::getPropertyClass( $this->class, $strProperty );
        $this->$strProperty = new $strClassName( $mixValue );

        continue;
      }

      if( $strPropertyType === 'collection' ) {
        $strCollectionClass        = self::getPropertyClass( $this->class, $strProperty );
        $strCollectionAllowedClass = self::getCollectionAllowedType( $this->class, $strProperty );
        $this->$strProperty        = new $strCollectionClass();

        foreach( $mixValue as $strId ) {
          $this->$strProperty->push( new $strCollectionAllowedClass( $strId ) );
        }
      }
    }

    return true;
  }

  /**
   * This method resolves the allowed element class of a collection
   * property via reflection: it instantiates the declared collection
   * class without constructor and asks its getAllowedType(). It is
   * used by fillObject() to build the element instances from the
   * stored id list, by rawObject() to report the element class in
   * the frontend envelopes and by referenceProperties() to find the
   * collection properties that reference a deleted class. The declared
   * collection type must be concrete — an abstract collection type
   * would throw a ReflectionException (fail loud).
   *
   * @param     string    $strClass      The class name owning the property
   * @param     string    $strProperty   The name of the collection property
   * @return    string    The class name of the allowed element type
   *
   * @example   $strClassName = BaseObject::getCollectionAllowedType( 'User', 'users' );
   *
   */
  private static function getCollectionAllowedType( string $strClass, string $strProperty ) : string {
    $objReflectionProperty = new ReflectionProperty( $strClass, $strProperty );
    $strCollectionClass    = $objReflectionProperty->getType()->getName();
    $objReflectionClass    = new ReflectionClass( $strCollectionClass );
    $objInstanz            = $objReflectionClass->newInstanceWithoutConstructor();

    return $objInstanz->getAllowedType();
  }

  /**
   * This method resolves the declared type category of a property by
   * reflection: 'builtin' for scalar and array types, 'collection' for
   * BaseCollection and its subclasses, 'class' for business object
   * references and 'undefined' when the property carries no type hint.
   * It drives the branch selection in fillObject() and the reference
   * property scan of the delete propagation.
   *
   * @param     string    $strClass      The class name owning the property
   * @param     string    $strProperty   The name of the property
   * @return    string    The type category (builtin, collection, class or undefined)
   *
   * @example   $strType = BaseObject::getPropertyType( 'User', 'roleId' );
   *
   */
  private static function getPropertyType( string $strClass, string $strProperty ) : string {
    $objReflectionProperty = new ReflectionProperty( $strClass, $strProperty );

    if( ! $objReflectionProperty->hasType() ) return 'undefined';
    if( $objReflectionProperty->getType()->isBuiltin() ) return 'builtin';
    if( $objReflectionProperty->getType()->getName() === 'BaseCollection' ) return 'collection';
    if( is_subclass_of( $objReflectionProperty->getType()->getName(), 'BaseCollection' ) ) return 'collection';

    return 'class';
  }

  /**
   * This method returns the declared class name of a typed property as a
   * string. It is used to instantiate business object references and
   * collection properties from the stored id during fillObject() and to
   * validate the reference class in the generic save route. The type
   * name is read via getName(), so a nullable declaration returns the
   * bare class name without the '?' prefix. Returns null when the
   * property carries no type hint.
   *
   * @param     string         $strClass      The class name owning the property
   * @param     string         $strProperty   The name of the property
   * @return    string|null    The declared class name or null
   *
   * @example   $strClass = BaseObject::getPropertyClass( 'User', 'role' );
   *
   */
  private static function getPropertyClass( string $strClass, string $strProperty ) : string | null {
    $objReflectionProperty = new ReflectionProperty( $strClass, $strProperty );

    if( ! $objReflectionProperty->hasType() ) return null;

    return $objReflectionProperty->getType()->getName();
  }

  /**
   * This method renders the view output: it assigns the serialized config
   * as template variables and processes every template of the view's
   * template chain (templateNames() of the View object). The concatenated
   * template output is returned.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    string        The concatenated template output
   *
   * @example   $strContent = $objBaseObject->view( $objController );
   *
   */
  public function view( Controller $objController ) : string {
    $objController->presentation()->assignTemplateVar( 'default', 'config', $this->config()->serializeObject() );
    $objController->presentation()->assignTemplateVar( 'config', 'Translate', $this->config()->serializeObject() );

    return $objController->presentation()->renderView( $objController->viewObject() );
  }

  /**
   * This method is the default json view stub and returns an empty stdClass.
   * Views with resultType json override it with their own payload.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    object        The json payload of the view
   *
   * @example   $objPayload = $objBaseObject->viewJson( $objController );
   *
   */
  public function viewJson( Controller $objController ) : object {
    return new stdClass();
  }

  /**
   * This method serves the json view behind index.php?view=viewNewObject&newClass=...
   * and returns a blueprint instance in the collector envelope format for
   * on-demand object creation in the frontend. Invalid newClass values arrive
   * from the request layer already filtered as null — the response is then
   * deliberately empty instead of an AppError, because json views do not
   * carry an AppError channel.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    array        The blueprint envelope or an empty stdClass
   *
   * @example   $objEnvelope = $objBaseObject->viewNewObject( $objController );
   *
   */
  public function viewNewObject( Controller $objController ) : array {
    $strNewClass = Request::param( 'newClass' );

    if( ! isset( $strNewClass ) ) return [ new stdClass() ];

    $objBlueprint = BaseObject::createBlueprint( $strNewClass );
    $arrBlueprint = $objBlueprint->rawObject( $objController );

    return $arrBlueprint;
  }

  /**
   * This method serves the json view behind index.php?view=viewLoadObject&class=...
   * and returns a object instance in the collector envelope format for
   * on-demand object loading in the frontend. Invalid class values or
   * invalid id values or the object dont exists arrive
   * from the request layer already filtered as null — the response is then
   * deliberately empty instead of an AppError, because json views do not
   * carry an AppError channel. The classPermissions whitelist of the view
   * guards the read like the save route (isClassAllowed): a class the map
   * does not declare and a failed role id / owner check both answer
   * deliberately empty — a denied load hides behind the not-found
   * convention (no existence leak).
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    array        The object envelope or an empty stdClass
   *
   * @example   $objEnvelope = $objBaseObject->viewLoadObject( $objController );
   *
   */
  public function viewLoadObject( Controller $objController ) : array {
    $strClass = Request::param( 'class' );
    $strId    = Request::param( 'id' );

    if( ! isset( $strClass ) || ! isset( $strId ) ) return [];
    if( ! self::existsObject( $strClass, $strId ) ) return [];

    $arrAllowed = self::getClassPermissions( $objController, $strClass );

    if( ! isset( $arrAllowed ) ) return [];

    $objObject = new $strClass( $strId );

    if( ! $this->isClassAllowed( $objController, $objObject, $arrAllowed ) ) return [];

    return $objObject->rawObject();
  }

  public function viewLoadObjects( Controller $objController ) : array {
    $strClass   = Request::param( 'class' );
    $arrAllowed = self::getClassPermissions( $objController, $strClass );
    $arrResult  = [];

    if( ! isset( $strClass ) ) return [];
    if( ! isset( $arrAllowed ) ) return [];

    $arrObjects = self::getObjectsFromClass( $strClass );

    if( ! isset( $arrObjects ) ) return [];

    foreach( $arrObjects as $objObject ) {
      if( ! $this->isClassAllowed( $objController, $objObject, $arrAllowed ) ) continue;

      array_push( $arrResult, ...$objObject->rawObject() );
    }

    return $arrResult;
  }

  /**
   * This method serves the generic json save route behind
   * index.php?view=viewSaveObject (POST): the request parameter 'object'
   * carries the envelope { class, id, data } the client wants to persist —
   * with the blueprint id the route creates (server generated id), without
   * it the client addresses an existing object (merge update). The route
   * accepts only CRUD-capable classes: a BaseObject subclass with a field
   * definition — every other class name is silently filtered (like
   * viewLoadObject, json views carry no information channel for foreign
   * classes). Beyond the class gate the route enforces the classPermissions
   * whitelist of the view: a class the map does not declare answers
   * silently empty (as one), a declared class must pass the role id /
   * owner check (isClassAllowed) — a failed check gets the permission
   * AppError envelope; at a create the ownerId of the logged-in user is
   * set on the new instance (the pseudo role 'owner' can only fire on
   * updates, a blueprint has no owner yet). The merged values follow the
   * field contract: only declared
   * fields arrive on the instance, NOSERIALIZE/NOSAVE properties and
   * password fields are never merged, fields with a roleIds attribute only
   * for roles the definition allows and the own id is immutable — at a
   * create the delivered id is taken over the declared id field contract,
   * the blueprint id itself is ignored (the server generates the id).
   * Reference properties (class/collection typed) arrive as the mirrored
   * collector descriptors (propertyType, class, value) and are merged
   * through mergeReferenceProperty(): the declared class comes from the
   * property reflection, every delivered id is existence-checked, empty
   * values clear the reference. Rejections travel as AppError envelopes
   * (validation field errors and reference errors one envelope per field,
   * the onSave hook and the guards one envelope per rejection); on
   * success the response is the rawObject (envelope array) of the saved
   * instance.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    array         The envelope array of the saved object or the rejection AppError envelopes
   *
   * @example   $arrEnvelopes = $objBaseObject->viewSaveObject( $objController );
   *
   */
  public function viewSaveObject( Controller $objController ) : array {
    $strObjectParam = Request::param( 'object' );

    if( ! isset( $strObjectParam ) ) return [];

    $objEnvelope = json_decode( $strObjectParam );

    if( ! is_object( $objEnvelope )
      || ! isset( $objEnvelope->class ) || ! is_string( $objEnvelope->class )
      || ! isset( $objEnvelope->id )    || ! is_string( $objEnvelope->id )    || $objEnvelope->id == ''
      || ! isset( $objEnvelope->data )  || ! is_object( $objEnvelope->data ) ) {
      return self::crudErrorEnvelope( 'Ungültiges Objekt übermittelt.', 'viewSaveObject' );
    }

    $strClass   = $objEnvelope->class;
    $strId      = $objEnvelope->id;
    $objData    = $objEnvelope->data;
    $boolCreate = $strId === self::BLUEPRINTID;

    if( ! self::isCrudClass( $strClass ) ) return [];

    $arrAllowed = self::getClassPermissions( $objController, $strClass );

    if( ! isset( $arrAllowed ) ) return [];

    if( $boolCreate ) {
      if( ! in_array( $objController->role()->id(), $arrAllowed, true ) ) {
        return self::crudErrorEnvelope( 'Keine Berechtigung für dieses Objekt.', 'viewSaveObject' );
      }

      $objObject = ( new ReflectionClass( $strClass ) )->newInstanceWithoutConstructor();
      $objObject->initObject( null );
      $objObject->ownerId = $objController->user()->id();
    } else {
      if( ! self::existsObject( $strClass, $strId ) ) {
        return self::crudErrorEnvelope( 'Das zu speichernde Objekt existiert nicht.', 'viewSaveObject' );
      }

      $objObject = new $strClass( $strId );

      if( ! $this->isClassAllowed( $objController, $objObject, $arrAllowed ) ) {
        return self::crudErrorEnvelope( 'Keine Berechtigung für dieses Objekt.', 'viewSaveObject' );
      }
    }

    $objFields         = BaseObject::getFieldsOfClass( $strClass );
    $strCurrentRoleId  = $objController->role()->id();
    $arrReferenceErrors = [];

    foreach( $objData as $strProperty => $mixValue ) {
      if( ! property_exists( $strClass, $strProperty ) ) continue;
      if( ! isset( $objFields->$strProperty ) ) continue;
      if( in_array( $strProperty, $strClass::NOSERIALIZE ) ) continue;
      if( in_array( $strProperty, $strClass::NOSAVE ) ) continue;
      if( $strProperty === 'id' && ( ! $boolCreate || $mixValue === self::BLUEPRINTID ) ) continue;
      if( ( $objFields->$strProperty->type ?? null ) === 'password' ) continue;
      if( isset( $objFields->$strProperty->roleIds ) && ! in_array( $strCurrentRoleId, $objFields->$strProperty->roleIds, true ) ) continue;

      $strPropertyType = self::getPropertyType( $strClass, $strProperty );

      if( $strPropertyType === 'class' || $strPropertyType === 'collection' ) {
        $strReferenceError = $this->mergeReferenceProperty( $objObject, $strProperty, $mixValue );

        if( isset( $strReferenceError ) ) $arrReferenceErrors[] = $strReferenceError;

        continue;
      }

      $objObject->$strProperty = $mixValue;
    }

    $arrFieldErrors = array_merge( FieldValidator::validateData( $strClass, $objData, $boolCreate, $objController ), $arrReferenceErrors );

    if( count( $arrFieldErrors ) > 0 ) {
      $arrErrorEnvelopes = [];

      foreach( $arrFieldErrors as $strMessage ) {
        array_push( $arrErrorEnvelopes, ...self::crudErrorEnvelope( $strMessage, 'viewSaveObject' ) );
      }

      return $arrErrorEnvelopes;
    }

    $strHookError = $objObject->onSave( $objController );

    if( isset( $strHookError ) ) return self::crudErrorEnvelope( $strHookError, 'onSave' );

    if( $boolCreate ) {
      $objEntry = $objObject->serializeObjectForSave();

      if( ! BaseObject::insertObject( $strClass, $objObject->id(), $objEntry ) ) {
        return self::crudErrorEnvelope( 'Die Objekt-ID ist bereits vergeben.', 'viewSaveObject' );
      }
    } else {
      $objObject->saveObject();
    }

    return $objObject->rawObject();
  }

  /**
   * This private method merges one reference value of the save envelope
   * onto the instance: the value arrives as the mirrored collector
   * descriptor (propertyType, class, value). The declared reference class
   * comes from the property reflection and never from the request — a
   * malformed descriptor, a class mismatch, a propertyType mismatch and a
   * delivered unknown id are rejected with a message (the caller collects
   * them into the AppError envelopes). Empty object values (null or the
   * empty string) clear the property (unset — serializeObjectForSave()
   * drops uninitialized properties), empty collection values persist as
   * an empty id list. A valid object reference is set as the instantiated
   * business object (persisted as its id), a valid collection as a
   * freshly built collection of element instances (persisted as the id
   * list).
   *
   * @param     BaseObject    $objObject     The object to merge onto
   * @param     string        $strProperty   The reference property name
   * @param     mixed         $mixValue      The delivered reference value (descriptor)
   * @return    string|null   The rejection message or null
   *
   * @example   $strError = $this->mergeReferenceProperty( $objObject, 'demoTwo', $mixValue );
   *
   * @see       BaseObject::viewSaveObject
   *
   */
  private function mergeReferenceProperty( BaseObject $objObject, string $strProperty, mixed $mixValue ) : ?string {
    if( ! is_object( $mixValue )
      || ! isset( $mixValue->propertyType ) || ! is_string( $mixValue->propertyType )
      || ! isset( $mixValue->class )       || ! is_string( $mixValue->class )       || $mixValue->class == '' ) {
      return 'Das Referenz-Format des Feldes ' . $strProperty . ' ist ungültig.';
    }

    if( self::getPropertyType( $objObject->className(), $strProperty ) === 'class' ) {
      if( $mixValue->propertyType !== 'object' ) {
        return 'Das Feld ' . $strProperty . ' erwartet eine Objekt-Referenz.';
      }

      $strReferenceClass = self::getPropertyClass( $objObject->className(), $strProperty );

      if( $mixValue->class !== $strReferenceClass ) {
        return 'Das Feld ' . $strProperty . ' referenziert eine nicht deklarierte Klasse.';
      }

      if( ! isset( $mixValue->value ) || $mixValue->value == '' ) {
        unset( $objObject->$strProperty );

        return null;
      }

      if( ! is_string( $mixValue->value ) || ! self::existsObject( $strReferenceClass, $mixValue->value ) ) {
        return 'Das referenzierte Objekt des Feldes ' . $strProperty . ' existiert nicht.';
      }

      $objObject->$strProperty = new $strReferenceClass( $mixValue->value );

      return null;
    }

    if( $mixValue->propertyType !== 'collection' ) {
      return 'Das Feld ' . $strProperty . ' erwartet eine Sammlungs-Referenz.';
    }

    $strCollectionClass = self::getPropertyClass( $objObject->className(), $strProperty );
    $strAllowedClass    = self::getCollectionAllowedType( $objObject->className(), $strProperty );

    if( $mixValue->class !== $strAllowedClass ) {
      return 'Das Feld ' . $strProperty . ' referenziert eine nicht deklarierte Klasse.';
    }

    if( ! is_array( $mixValue->value ) ) {
      return 'Das Referenz-Format des Feldes ' . $strProperty . ' ist ungültig.';
    }

    $objCollection = new $strCollectionClass();

    foreach( $mixValue->value as $strElementId ) {
      if( ! is_string( $strElementId ) || ! self::existsObject( $strAllowedClass, $strElementId ) ) {
        return 'Ein referenziertes Objekt des Feldes ' . $strProperty . ' existiert nicht.';
      }

      $objCollection->push( new $strAllowedClass( $strElementId ) );
    }

    $objObject->$strProperty = $objCollection;

    return null;
  }

  /**
   * This method serves the generic json delete route behind
   * index.php?view=viewDeleteObject (POST): the request parameters class
   * and id address the envelope to remove. The route accepts only
   * CRUD-capable classes (a BaseObject subclass with a field definition,
   * see viewSaveObject) — every other class name is silently filtered.
   * The classPermissions whitelist of the view guards the delete like the
   * save route: an undeclared class answers silently empty, a failed
   * role id / owner check gets the permission AppError envelope — both
   * before the onDelete hook. The onDelete hook runs before the
   * persistence and can abort the delete
   * with its message as an AppError envelope. A successful response
   * carries the rawObject envelopes of the deleted object (reference sub
   * envelopes in front, own envelope last — the client registers the
   * instance before remove fires the delete events), a lost delete race
   * or hook rejection reports the AppError envelopes.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @return    array         The deleted object envelopes on success or the rejection AppError envelopes
   *
   * @example   $arrEnvelopes = $objBaseObject->viewDeleteObject( $objController );
   *
   */
  public function viewDeleteObject( Controller $objController ) : array {
    $strClass = Request::param( 'class' );
    $strId    = Request::param( 'id' );

    if( ! isset( $strClass ) || ! isset( $strId ) ) return [];
    if( ! self::isCrudClass( $strClass ) ) return [];
    if( ! self::existsObject( $strClass, $strId ) ) {
      return self::crudErrorEnvelope( 'Das zu löschende Objekt existiert nicht.', 'viewDeleteObject' );
    }

    $arrAllowed = self::getClassPermissions( $objController, $strClass );

    if( ! isset( $arrAllowed ) ) return [];

    $objObject = new $strClass( $strId );

    if( ! $this->isClassAllowed( $objController, $objObject, $arrAllowed ) ) {
      return self::crudErrorEnvelope( 'Keine Berechtigung für dieses Objekt.', 'viewDeleteObject' );
    }

    $strHookError = $objObject->onDelete( $objController );

    if( isset( $strHookError ) ) return self::crudErrorEnvelope( $strHookError, 'onDelete' );

    if( ! $objObject->deleteObject() ) {
      return self::crudErrorEnvelope( 'Das Objekt konnte nicht gelöscht werden.', 'viewDeleteObject' );
    }

    return $objObject->rawObject();
  }

  /**
   * This private method is the class gate of the generic json routes:
   * only a BaseObject subclass with a non-empty field definition is
   * CRUD-capable — the field contract is the single declaration that
   * opens class, id and data of the envelope for the route. Core classes
   * (controller, views, roles...) carry no field definitions and stay
   * unreachable, so the generic route can never write engine files.
   *
   * @param     string    $strClassName   The name of the business class
   * @return    boolean   True when the class is CRUD-capable, false otherwise
   *
   * @example   if( ! BaseObject::isCrudClass( $strClass ) ) return [];
   *
   */
  private static function isCrudClass( string $strClassName ) : bool {
    if( ! class_exists( $strClassName ) || ! is_subclass_of( $strClassName, BaseObject::class ) ) return false;

    return count( (array) BaseObject::getFieldsOfClass( $strClassName ) ) > 0;
  }

  /**
   * This method reads the allowed role ids of the addressed class from
   * the classPermissions map of the request view. The map is a
   * whitelist: a view without a map and a class that the map does not
   * declare both return null — the calling routes treat null as the
   * silent empty answer (same convention as the isCrudClass gate, no
   * information channel about foreign classes). A declared class
   * returns its role id list which may contain the pseudo role 'owner'
   * next to real role ids.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @param     string        $strClass        The addressed business class
   * @return    array|null    The allowed role ids of the class or null when not declared
   *
   * @example   $arrAllowed = self::getClassPermissions( $objController, $strClass );
   *
   * @see       BaseObject::isClassAllowed
   *
   */
  private static function getClassPermissions( Controller $objController, string $strClass ) : array | null {
    $arrClassPermissions = $objController->viewObject()->classPermissions();

    if( ! isset( $arrClassPermissions ) ) return null;

    return $arrClassPermissions[ $strClass ] ?? null;
  }

  /**
   * This method decides the class permission of the request over the
   * allowed role id list of the addressed object's class (see
   * getClassPermissions): the role of the user is allowed when listed,
   * the user is allowed when the list declares the pseudo role 'owner'
   * and the object carries the user id as its ownerId (the creator).
   * The owner check cannot fire on a create — a blueprint has no owner
   * yet, so creates are role-guarded only.
   *
   * @param     Controller    $objController   The controller instance of the request
   * @param     BaseObject    $objObject       The addressed (loaded) object
   * @param     array         $arrAllowed      The allowed role ids of the class
   * @return    boolean       True when the request may treat the object, false otherwise
   *
   * @example   if( ! $this->isClassAllowed( $objController, $objObject, $arrAllowed ) ) return [];
   *
   * @see       BaseObject::getClassPermissions
   *
   */
  private function isClassAllowed( Controller $objController, BaseObject $objObject, array $arrAllowed ) : bool {
    if( in_array( $objController->role()->id(), $arrAllowed, true ) ) return true;

    $strOwnerId = $objObject->ownerId();

    if( in_array( 'owner', $arrAllowed, true ) && isset( $strOwnerId ) && $strOwnerId === $objController->user()->id() ) return true;

    return false;
  }

  /**
   * This private method builds the rejection envelope(s) of the generic
   * json routes: one AppError instance per message (id pattern
   * error{N} over a route-local counter), returned as its rawObject
   * envelope array — the client overlays report the cards themselves
   * (loader error display), the same channel the html views use.
   *
   * @param     string    $strMessage   The rejection message for the frontend
   * @param     string    $strSource    The route or hook source (e.g. 'viewSaveObject')
   * @return    array     The AppError envelope as single-element array
   *
   * @example   return self::crudErrorEnvelope( 'Ungültiges Objekt übermittelt.', 'viewSaveObject' );
   *
   */
  private static function crudErrorEnvelope( string $strMessage, string $strSource ) : array {
    self::$intErrorEnvelopeCount++;

    $objError = new AppError( 'error' . self::$intErrorEnvelopeCount );
    $objError->setMessage( $strMessage, $strSource, 'error' );

    return $objError->rawObject();
  }

  /**
   * This method serializes the object for the frontend payload.
   * Unset properties and properties filtered by the NOSERIALIZE
   * constant are removed (e.g. the password hash of a user).
   *
   * @return    object   The serialized object
   *
   * @example   $objPayload = $objBaseObject->serializeObject();
   *
   */
  public function serializeObject() : object {
    $objStdObject  = new StdClass();
    $objProperties = get_object_vars( $this );

    foreach( $objProperties as $strKey => $strValue ) {
      if( ! isset( $strValue ) ) continue;
      if( in_array( $strKey, $this::NOSERIALIZE ) ) continue;

      $objStdObject->$strKey = $strValue;
    }

    return $objStdObject;
  }

  /**
   * This method builds the collector envelope [ { class, id, data } ... ]
   * of the object array for the frontend. Every referenced business
   * object ('class' typed) and collection property is embedded into the
   * own data payload as a descriptor (with its id respectively its
   * element id list), replacing the raw instance value from
   * serializeObject() — for multiple reference properties all of their
   * descriptors are embedded.
   *
   * @param     Controller|null   $objController   The controller object or null
   * @return    array             The collector envelope of the object array
   *
   * @example   $objEnvelope = $objBaseObject->rawObject( $objController );
   *
   */
  public function rawObject( Controller | null $objController = null, array | null &$arrResult = [] ) : array {
    $arrDescriptors = [];
    $arrNoSerialize = self::NOSERIALIZE;

    foreach( $this as $strProperty => $mixValue ) {
      if( in_array( $strProperty, $arrNoSerialize ) ) continue;

      if( self::getPropertyType( $this->class, $strProperty ) === 'class' ) {
        $objPropertyValue               = new stdClass();

        $objPropertyValue->propertyType = 'object';
        $objPropertyValue->property     = $strProperty;
        $objPropertyValue->class        = self::getPropertyClass( $this->class, $strProperty );
        $objPropertyValue->value        = $mixValue->id;

        $arrDescriptors[] = $objPropertyValue;

        $this->$strProperty->rawObject( $objController, $arrResult );
      }

      if( self::getPropertyType( $this->class, $strProperty ) === 'collection' ) {
        $arrCollectionValue             = $mixValue->getAll();
        $objPropertyValue               = new stdClass();
        $objPropertyValue->propertyType = 'collection';
        $objPropertyValue->property     = $strProperty;
        $objPropertyValue->class        = self::getCollectionAllowedType( $this->class, $strProperty );
        $objPropertyValue->value        = $mixValue->idArray();

        foreach( $arrCollectionValue as $objCollectionValue ) {
          $objCollectionValue->rawObject( $objController, $arrResult );
        }

        $arrDescriptors[] = $objPropertyValue;
      }
    }

    $objRawObject        = new StdClass();
    $objRawObject->class = $this->class;
    $objRawObject->id    = $this->id;
    $objRawObject->data  = $this->serializeObject();

    foreach( $arrDescriptors as $objPropertyVal ) {
      $strProperty                      = $objPropertyVal->property;
      $objRawObject->data->$strProperty = $objPropertyVal;
    }

    $arrResult[] = $objRawObject;

    return $arrResult;
  }

  /**
   * This method persists the object: it writes only the own entry back into
   * the class json file. The NOSAVE constant filters technical runtime properties
   * from the payload; NOSERIALIZE deliberately does NOT apply here — a user's
   * password hash must be persisted after all. The whole read-modify-write cycle
   * runs under an exclusive file lock on <file>.lock (see withFileLock).
   *
   * @return    void
   *
   * @example   $objBaseObject->saveObject();
   *
   */
  public function saveObject() : void {
    BaseObject::withFileLock( $this->dataJsonFile, function() {
      $objObjects = BaseObject::loadFileDeCrypted( $this->dataJsonFile );

      if( ! isset( $objObjects ) ) $objObjects = new stdClass();

      $objObjects->{ $this->id } = $this->serializeObjectForSave();

      BaseObject::saveFileEnCrypted( $this->dataJsonFile, $objObjects );
    } );

    return;
  }

  /**
   * This method serializes the object for persistence. Unset properties
   * and properties filtered by the NOSAVE constant are removed. Referenced
   * business objects ('class' typed properties) are serialized as their id
   * and collection properties ('collection' typed) as the id list of their
   * elements — fillObject() reads both forms back as instances. Builtin
   * properties keep their value. NOSERIALIZE deliberately does NOT apply
   * here — a user's password hash must be persisted after all.
   *
   * @return    object   The serialized object for persistence
   *
   * @example   $objSaveObject = $objBaseObject->serializeObjectForSave();
   *
   */
  public function serializeObjectForSave() : object {
    $objStdObject = new StdClass();

    foreach( get_object_vars( $this ) as $strKey => $mixValue ) {
      if( ! isset( $mixValue ) ) continue;
      if( in_array( $strKey, $this::NOSAVE ) ) continue;

      $strPropertyType = self::getPropertyType( $this->class, $strKey );

      if( $strPropertyType === 'collection' ) {
        $objStdObject->$strKey = $mixValue->idArray();

        continue;
      }

      if( $strPropertyType === 'class' ) {
        $objStdObject->$strKey = $mixValue->id();

        continue;
      }

      $objStdObject->$strKey = $mixValue;
    }

    return $objStdObject;
  }

  /**
   * This method is the delete propagation (phase A): before the object
   * deletes itself it cleans every other loaded business class that
   * declares the own class as a reference property. The scan works on
   * the serialized entries (a class reference is stored as the id
   * string, a collection reference as the id list) and runs per class
   * under its own file lock; classes without a matching reference
   * property are skipped by reflection alone, without reading their
   * data file. A changed file is written back once, unchanged files
   * stay untouched. There is no reference index by design — the scan
   * reads the single source of truth, so it cannot drift.
   *
   * @return    void
   *
   * @example   $objBaseObject->deleteReferences();
   *
   */
  private function deleteReferences() : void {
    foreach( get_declared_classes() as $strClass ) {
      if( ! is_subclass_of( $strClass, BaseObject::class ) ) continue;
      if( $strClass === $this->class ) continue;

      $arrProperties = self::referenceProperties( $strClass, $this->class );

      if( count( $arrProperties ) === 0 ) continue;

      $strFile = BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClass ) . '.json';

      if( ! file_exists( $strFile ) ) continue;

      BaseObject::withFileLock( $strFile, function() use ( $strFile, $arrProperties ) : void {
        $objObjects = BaseObject::loadFileDeCrypted( $strFile );

        if( ! isset( $objObjects ) ) return;

        if( ! self::cleanEntries( $objObjects, $arrProperties, $this->id ) ) return;

        BaseObject::saveFileEnCrypted( $strFile, $objObjects );
      } );
    }

    return;
  }

  /**
   * This method returns the reference properties of a class that declare
   * the given target class: an array of property names mapped to their
   * type category ('class' or 'collection'), empty when the class does
   * not reference the target. It drives the reflection filter of the
   * delete propagation — the cheapest possible check, no data file is
   * read for a non-matching class.
   *
   * @param     string    $strClass         The class name to scan
   * @param     string    $strTargetClass   The class name the properties must reference
   * @return    array     The matching properties (name => 'class' | 'collection')
   *
   * @example   $arrProperties = BaseObject::referenceProperties( 'DemoOne', 'DemoTwo' );
   *
   */
  private static function referenceProperties( string $strClass, string $strTargetClass ) : array {
    $arrResult     = [];
    $objReflection = new ReflectionClass( $strClass );

    foreach( $objReflection->getProperties() as $objReflectionProperty ) {
      if( ! $objReflectionProperty->hasType() ) continue;

      $objType     = $objReflectionProperty->getType();
      $strTypeName = $objType->getName();

      if( $objType->isBuiltin() ) continue;

      if( $strTypeName === 'BaseCollection' || is_subclass_of( $strTypeName, 'BaseCollection' ) ) {
        $objCollection = ( new ReflectionClass( $strTypeName ) )->newInstanceWithoutConstructor();

        if( $objCollection->getAllowedType() === $strTargetClass ) $arrResult[ $objReflectionProperty->getName() ] = 'collection';

        continue;
      }

      if( $strTypeName === $strTargetClass ) $arrResult[ $objReflectionProperty->getName() ] = 'class';
    }

    return $arrResult;
  }

  /**
   * This method removes a target id from the serialized reference
   * properties of every entry in the loaded class file content: a
   * class property matching the id is unset (the property vanishes
   * from persistence, same convention as the empty reference in
   * mergeReferenceProperty), a collection property containing the id
   * is rebuilt as the filtered id list keeping the order of the
   * remaining ids. Entries are modified in place (objects are
   * handles); the caller decides whether and under which lock to
   * write the content back. An optional skip id leaves one entry
   * untouched (the deleting object itself in the own class file).
   *
   * @param     object    $objObjects        The loaded class file content (id => serialized entry)
   * @param     array     $arrProperties     The reference properties to clean (name => 'class' | 'collection')
   * @param     string    $strTargetId       The object id to remove from the references
   * @param     string    $strSkipId         An entry id to leave untouched (empty: none)
   * @return    boolean   True when at least one entry was changed, false otherwise
   *
   * @example   $boolChanged = self::cleanEntries( $objObjects, $arrProperties, $strTargetId );
   *
   */
  private static function cleanEntries( object $objObjects, array $arrProperties, string $strTargetId, string $strSkipId = '' ) : bool {
    $boolChanged = false;

    foreach( $objObjects as $strId => $objEntry ) {
      if( $strId === $strSkipId ) continue;

      foreach( $arrProperties as $strProperty => $strPropertyType ) {
        if( $strPropertyType === 'class' ) {
          if( isset( $objEntry->$strProperty ) && $objEntry->$strProperty === $strTargetId ) {
            unset( $objEntry->$strProperty );

            $boolChanged = true;
          }

          continue;
        }

        if( ! isset( $objEntry->$strProperty ) || ! is_array( $objEntry->$strProperty ) ) continue;
        if( ! in_array( $strTargetId, $objEntry->$strProperty, true ) ) continue;

        $objEntry->$strProperty = array_values( array_filter( $objEntry->$strProperty, fn( string $strElementId ) : bool => $strElementId !== $strTargetId ) );

        $boolChanged = true;
      }
    }

    return $boolChanged;
  }

  /**
   * This method deletes the own entry from the class json file.
   * It returns false when the file or the entry does not exist.
   * The whole read-modify-write cycle runs under an exclusive file
   * lock on <file>.lock (see withFileLock).
   *
   * The delete propagation runs BEFORE the own entry is removed: phase
   * A (deleteReferences) cleans every other loaded business class that
   * references the own class, phase B cleans self-references of the
   * own class inline within this lock (a nested saveObject would hit
   * the held lock and time out). If the propagation fails (e.g. lock
   * timeout), the own entry stays intact — the framework routes the
   * exception over the soft error channel and a retry heals, because
   * the scan is idempotent.
   *
   * @return    boolean   True when the entry was deleted, false otherwise
   *
   * @example   $objBaseObject->deleteObject();
   *
   */
  public function deleteObject() : bool {
    if( ! file_exists( $this->dataJsonFile ) ) return false;
    if( ! self::existsObject( $this->class, $this->id ) ) return false;

    $this->deleteReferences();

    return BaseObject::withFileLock( $this->dataJsonFile, function() : bool {
      $objObjects = BaseObject::loadFileDeCrypted( $this->dataJsonFile );

      if( ! isset( $objObjects ) || ! isset( $objObjects->{ $this->id } ) ) return false;

      $arrProperties = self::referenceProperties( $this->class, $this->class );

      if( count( $arrProperties ) > 0 ) self::cleanEntries( $objObjects, $arrProperties, $this->id, $this->id );

      unset( $objObjects->{ $this->id } );

      BaseObject::saveFileEnCrypted( $this->dataJsonFile, $objObjects );

      return true;
    } );
  }

  /**
   * This method inserts a new entry under a self-chosen id into the class json file.
   * The class name is passed as a parameter, the file path is derived from it
   * internally via lcfirst. It returns false when the id is already taken.
   * The method deliberately bypasses the constructor — this way user instances
   * for example cannot be created "empty-new" (getUser fallback). The whole
   * read-modify-write cycle including the already-taken check runs under an
   * exclusive file lock on <file>.lock (see withFileLock): two concurrent
   * inserts of the same id resolve deterministically — one returns true,
   * the other false.
   *
   * @param     string    $strClassName   The name of the business class
   * @param     string    $strId          The self-chosen id of the new entry
   * @param     object    $objEntry       The entry to persist
   * @return    boolean   True when the entry was inserted, false otherwise
   *
   * @example   $boolSaved = BaseObject::insertObject( 'User', 'newUserId', $objEntry );
   *
   */
  public static function insertObject( string $strClassName, string $strId, object $objEntry ) : bool {
    if( $strId == '' ) return false;

    $strFile = BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClassName ) . '.json';

    return BaseObject::withFileLock( $strFile, function() use ( $strFile, $strId, $objEntry ) : bool {
      $objObjects = BaseObject::loadFileDeCrypted( $strFile );

      if( ! isset( $objObjects ) ) $objObjects = new stdClass();
      if( isset( $objObjects->{ $strId } ) ) return false;

      $objObjects->{ $strId } = $objEntry;

      BaseObject::saveFileEnCrypted( $strFile, $objObjects );

      return true;
    } );
  }

  /**
   * This method returns an instance of the requested class and id.
   * It returns null when the class name, the id or the entry is
   * empty or missing.
   *
   * @param     string          $strClassName   The name of the business class
   * @param     string          $strId          The id of the object
   * @return    object|null     The instance or null
   *
   * @example   $objUser = BaseObject::getObject( 'User', 'someUserId' );
   *
   */
  public static function getObject( string $strClassName, string $strId ) : object | null {
    if( ! isset( $strClassName ) || $strClassName == '' ) return null;
    if( ! isset( $strId ) || $strId == '' ) return null;
    if( ! BaseObject::existsObject( $strClassName, $strId ) ) return null;

    return new $strClassName( $strId );
  }

  /**
   * This method checks whether an entry with the given id exists
   * in the class json file.
   *
   * @param     string    $strClassName   The name of the business class
   * @param     string    $strId          The id of the object
   * @return    boolean   True when the entry exists, false otherwise
   *
   * @example   $boolExists = BaseObject::existsObject( 'User', 'someUserId' );
   *
   */
  public static function existsObject( string $strClassName, string $strId ) : bool {
    if( ! isset( $strClassName ) || $strClassName == '' ) return false;
    if( ! isset( $strId ) || $strId == '' ) return false;

    $objRawObjects = BaseObject::getRawObjectsFromClass( $strClassName );

    if( isset( $objRawObjects->$strId ) ) return true;

    return false;
  }

  /**
   * This method returns the decoded content of a class json file
   * or null when the class name is empty.
   *
   * @param     string         $strClassName   The name of the business class
   * @return    object|null    The decoded class file content or null
   *
   * @example   $objRawObjects = BaseObject::getRawObjectsFromClass( 'User' );
   *
   */
  public static function getRawObjectsFromClass( string $strClassName ) : object | null {
    if( ! isset( $strClassName ) || $strClassName == '' ) return null;
    if( ! file_exists( BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClassName ) . '.json' ) ) return null;

    $objRawObjects = BaseObject::loadFileDeCrypted( BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClassName ) . '.json' );

    return $objRawObjects;
  }

  /**
   * This static method returns a raw object (stdClass).
   * or null if the class name or id is empty.
   *
   * @param     string         $strClassName   The name of the business class
   * @param     string         $strId          The id of the business object
   * @return    object|null    The raw object from the business class or null
   *
   * @example   $objRawObject = BaseObject::getRawObject( 'User', 'max@musterman.de' );
   *
   */
  public static function getRawObject( string $strClassName, string $strId ) : object | null {
    if( ! isset( $strClassName ) || $strClassName == '' ) return null;
    if( ! isset( $strId ) || $strId == '' ) return null;
    if( ! file_exists( BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClassName ) . '.json' ) ) return null;

    $objRawObjects = BaseObject::loadFileDeCrypted( BaseObject::FILEPATHJSON . 'data/' . lcfirst( $strClassName ) . '.json' );

    return isset( $objRawObjects->$strId ) ? $objRawObjects->$strId : null;
  }

  /**
   * This method returns an instance of every entry in the class json file.
   * An empty array is returned when the class name is empty or the file
   * has no entries.
   *
   * @param     string    $strClassName   The name of the business class
   * @return    array     An array of instances of the class
   *
   * @example   $arrUsers = BaseObject::getObjectsFromClass( 'User' );
   *
   */
  public static function getObjectsFromClass( string $strClassName ) : array {
    if( ! isset( $strClassName ) || $strClassName == '' ) return [];

    $objRawObjects = BaseObject::getRawObjectsFromClass( $strClassName );
    $arrObjects    = [];

    if( ! isset( $objRawObjects ) ) return $arrObjects;

    foreach( $objRawObjects as $strId => $objRawObject ) {
      array_push( $arrObjects, new $strClassName( $strId ) );
    }

    return $arrObjects;
  }

  /**
   * This method returns the default app configuration (config.json).
   *
   * @return    Config   The config instance of the 'default' scope
   *
   * @example   $objConfig = BaseObject::config();
   *
   */
  public static function config() : Config {
    return new Config( 'default' );
  }

  /**
   * This method returns the passphrase array for the AES encryption.
   * It loads .cryptKeys.php (production, gitignored) with a fallback to
   * cryptKeys.php (repo placeholder) and memoizes the result statically.
   *
   * @return    array   The passphrase array with the keys passphrase1 and passphrase2
   *
   * @example   $arrCryptKeys = BaseObject::getCryptKeys();
   *
   */
  public static function getCryptKeys() : array {
    static $arrCryptKeys = null;

    if( $arrCryptKeys === null ) {
      if( file_exists( __DIR__ . '/../classes/.cryptKeys.php' ) ) {
        $arrCryptKeys = include __DIR__ . '/../classes/.cryptKeys.php';
      } else {
        $arrCryptKeys = include __DIR__ . '/../classes/cryptKeys.php';
      }
    }

    return $arrCryptKeys;
  }

  /**
   * This method hashes a string with SHA256 (one-way, no decryption).
   *
   * @param     string    $strContent   The string to hash
   * @return    string    The SHA256 hash of the string
   *
   * @example   $strHash = BaseObject::enCrypteOnly( $strContent );
   *
   */
  public static function enCrypteOnly ( string $strContent ) : string {
    return hash( 'sha256', $strContent );
  }

  /**
   * This method decrypts an AES-256-CBC encrypted string. The key is derived
   * from passphrase1 and the initialization vector from passphrase2 of the
   * crypt keys.
   *
   * @param     string    $strContent   The encrypted string
   * @return    string    The decrypted string
   *
   * @example   $strPlain = BaseObject::deCrypte( $strContent );
   *
   */
  public static function deCrypte( string $strContent ) : string {
    $arrCryptKeys = BaseObject::getCryptKeys();
    $strContent   = openssl_decrypt(
      $strContent, "AES-256-CBC",
      hash( 'sha256', $arrCryptKeys[ 'passphrase1' ] ), 0,
      substr( hash( 'sha256', $arrCryptKeys[ 'passphrase2' ] ), 0, 16 )
    );

    return $strContent;
  }

  /**
   * This method encrypts a string with AES-256-CBC. The key is derived
   * from passphrase1 and the initialization vector from passphrase2 of the
   * crypt keys.
   *
   * @param     string    $strContent   The plain string
   * @return    string    The encrypted string
   *
   * @example   $strEncrypted = BaseObject::enCrypte( $strContent );
   *
   */
  public static function enCrypte( string $strContent ) : string {
    $arrCryptKeys = BaseObject::getCryptKeys();
    $strContent   = openssl_encrypt(
      $strContent, "AES-256-CBC",
      hash( 'sha256', $arrCryptKeys[ 'passphrase1' ] ), 0,
      substr( hash( 'sha256', $arrCryptKeys[ 'passphrase2' ] ), 0, 16 )
    );

    return $strContent;
  }

  /**
   * This method reads and decodes a json file. It returns null when the
   * file does not exist — the persistence callers (saveObject, insertObject,
   * deleteObject) interpret this as the empty storage state of a class that
   * has never been persisted. It auto-detects whether the content is plain
   * JSON (local/dev) or encrypted (production) before decrypting. Line
   * breaks are stripped upfront so that encrypted content can be detected
   * safely.
   *
   * @param     string               $strFile   The file path to load
   * @return    object|array|null    The decoded file content or null when the file does not exist
   *
   * @example   $objContent = BaseObject::loadFileDeCrypted( $strFile );
   *
   */
  public static function loadFileDeCrypted( string $strFile ) : object | array | null {
    if( ! file_exists( $strFile ) ) return null;

    $strContent = file_get_contents( $strFile );
    $strContent = str_replace( array( "\r", "\n" ), '', $strContent );

    if( ! BaseObject::isJson( $strContent ) ) {
      $strContent = BaseObject::deCrypte( $strContent );
    }

    return json_decode( $strContent );
  }

  /**
   * This method json encodes an object, encrypts the content with AES-256-CBC
   * and writes it to the file with an exclusive lock.
   *
   * @param     string    $strFile      The file path to write
   * @param     object    $objObject    The object to persist
   * @return    void
   *
   * @example   BaseObject::saveFileEnCrypted( $strFile, $objObject );
   *
   */
  public static function saveFileEnCrypted( string $strFile, object $objObject ) : void {
    $strContent = json_encode( $objObject );

    // file_put_contents( $strFile . '.json', $strContent, LOCK_EX );

    if( BaseObject::isJson( $strContent ) ) {
      $strContent = BaseObject::enCrypte( $strContent );
    }

    file_put_contents( $strFile, $strContent, LOCK_EX );

    return;
  }

  /**
   * This static method runs a callback under an exclusive file lock on
   * <file>.lock — the surrounding critical section for the whole
   * read-modify-write cycles of the persistence methods (saveObject,
   * deleteObject, insertObject). The lock is acquired non-blocking in
   * a retry loop with short sleep times; after LOCKTIMEOUT seconds a
   * RuntimeException is thrown (the framework routes it over the
   * established error channels). The lock is always released in the
   * finally block, even when the callback throws. A held lock dies
   * with the process anyway, so a stale .lock side file next to the
   * class json file is harmless.
   *
   * @param     string     $strFile      The file path of the class json file (lock file: <path>.lock)
   * @param     callable   $fnCallback   The callback to run under the lock
   * @return    mixed      The return value of the callback
   *
   * @example   BaseObject::withFileLock( $strFile, function() use ( $strFile ) : bool { ... } );
   *
   */
  private static function withFileLock( string $strFile, callable $fnCallback ) : mixed {
    $objLockHandle = fopen( $strFile . '.lock', 'c' );

    if( $objLockHandle === false ) throw new RuntimeException( 'Die Lock-Datei konnte nicht geöffnet werden: ' . $strFile . '.lock' );

    $floatDeadline = microtime( true ) + BaseObject::LOCKTIMEOUT;

    while( ! flock( $objLockHandle, LOCK_EX | LOCK_NB ) ) {
      if( microtime( true ) >= $floatDeadline ) {
        fclose( $objLockHandle );

        throw new RuntimeException( 'Die Daten-Datei ist nach ' . BaseObject::LOCKTIMEOUT . ' Sekunden weiterhin gesperrt: ' . $strFile );
      }

      usleep( BaseObject::LOCKSLEEP );
    }

    try {
      return $fnCallback();
    } finally {
      flock( $objLockHandle, LOCK_UN );
      fclose( $objLockHandle );
    }
  }

}

// EOF
