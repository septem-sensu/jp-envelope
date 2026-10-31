<?php

declare( strict_types = 1 );

require_once ( __DIR__ . '/../classes/config.php' );

/**
 * This class is the base class of all business objects and provides their persistence.
 * The object state is serialized into includes/json/data/{className}s.json (key = object id);
 * the constructor fills the instance properties automatically from that file via fillObject().
 * Technical properties are filtered by the two filter constants: NOSERIALIZE removes properties
 * from the frontend payload, NOSAVE removes properties from persistence (both defined per subclass).
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.12
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
   * File path of the json configuration directory (params.json, roles.json, etc.).
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
    $this->dataJsonFile = $this::FILEPATHJSON . 'data/' . strtolower( $this->class ) . 's.json';

    $this->fillObject();

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
    $strFieldFile = BaseObject::FILEPATHFIELDS . strtolower( $strClassName ) . '.json';

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
   * @return    BaseObject   The blueprint instance with the id 'new'
   *
   * @example   $objBlueprint = BaseObject::createBlueprint( 'User' );
   *
   */
  public static function createBlueprint( string $strClassName ) : BaseObject {
    $objBlueprint = ( new ReflectionClass( $strClassName ) )->newInstanceWithoutConstructor();
    $objBlueprint->initObject( 'new' );

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
   * of the object in the class json file. Properties that do not exist
   * on the class are skipped, as are unset properties (fillObject never
   * overwrites already set properties). It is a no-op when the id, the
   * class name or the file is missing.
   *
   * @return    void
   *
   * @example   $objBaseObject->fillObject();
   *
   */
  public function fillObject() : void {
    if( ! isset( $this->id ) || $this->id == '' ) return;
    if( ! isset( $this->class ) || $this->class == '' ) return;
    if( ! file_exists( $this->dataJsonFile ) ) return;

    $objRawObject = self::getRawObject( $this->class, $this->id );

    if( ! isset( $objRawObject ) ) return;

    foreach( $objRawObject as $strProperty => $mixValue ) {
      $objField = isset( $this->fields->$strProperty ) && gettype( $this->fields->$strProperty ) === 'object' ? $this->fields->$strProperty : null;

      if( ! property_exists( $this->class, $strProperty ) ) continue;
      if( ! isset( $objField ) || ! isset( $objField->class ) || $objField->class === '' ) {
        $this->$strProperty = $mixValue;

        continue;
      }

      if( ! isset( $objField->isArray ) || ! $objField->isArray ) {


        continue;
      }



    }

    return;
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
   * @return    object        The blueprint envelope or an empty stdClass
   *
   * @example   $objEnvelope = $objBaseObject->viewNewObject( $objController );
   *
   */
  public function viewNewObject( Controller $objController ) : object {
    $strNewClass = Request::param( 'newClass' );

    if( ! isset( $strNewClass ) ) return new stdClass();

    return BaseObject::createBlueprint( $strNewClass )->rawObject();
  }

  public function viewLoadObject( Controller $objController ) : object {
    $strClass = Request::param( 'class' );
    $strId    = Request::param( 'id' );

    if( ! isset( $strClass ) || ! isset( $strId ) ) return new stdClass();
    if( ! self::existsObject( $strClass, $strId ) ) return new stdClass();

    $objObject = new $strClass( $strId );

    return $objObject->rawObject();
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
   * This method builds the collector envelope { class, id, data } of the object
   * for the frontend. The class name can be overridden for the rare case that
   * the frontend class differs from the PHP class name.
   *
   * @param     string|null   $strJsClassName   The frontend class name override or null
   * @return    object        The collector envelope of the object
   *
   * @example   $objEnvelope = $objBaseObject->rawObject();
   *
   */
  public function rawObject( string | null $strJsClassName = null ) : object {
    $objRawObject        = new StdClass();
    $objRawObject->class = $strJsClassName ?? $this->class;
    $objRawObject->id    = $this->id;
    $objRawObject->data  = $this->serializeObject();

    return $objRawObject;
  }

  /**
   * This method persists the object: it writes only the own entry back into
   * the class json file. The NOSAVE constant filters technical runtime properties
   * from the payload; NOSERIALIZE deliberately does NOT apply here — a user's
   * password hash must be persisted after all.
   *
   * @return    void
   *
   * @example   $objBaseObject->saveObject();
   *
   */
  public function saveObject() : void {
    $objObjects = BaseObject::loadFileDeCrypted( $this->dataJsonFile );

    if( ! isset( $objObjects ) ) $objObjects = new stdClass();

    $objObjects->{ $this->id } = $this->serializeObjectForSave();

    BaseObject::saveFileEnCrypted( $this->dataJsonFile, $objObjects );

    return;
  }

  /**
   * This method serializes the object for persistence.
   * Unset properties and properties filtered by the NOSAVE
   * constant are removed.
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

      $objStdObject->$strKey = $mixValue;
    }

    return $objStdObject;
  }

  /**
   * This method deletes the own entry from the class json file.
   * It returns false when the file or the entry does not exist.
   *
   * @return    boolean   True when the entry was deleted, false otherwise
   *
   * @example   $objBaseObject->deleteObject();
   *
   */
  public function deleteObject() : bool {
    if( ! file_exists( $this->dataJsonFile ) ) return false;

    $objObjects = BaseObject::loadFileDeCrypted( $this->dataJsonFile );

    if( ! isset( $objObjects ) || ! isset( $objObjects->{ $this->id } ) ) return false;

    unset( $objObjects->{ $this->id } );

    BaseObject::saveFileEnCrypted( $this->dataJsonFile, $objObjects );

    return true;
  }

  /**
   * This method inserts a new entry under a self-chosen id into the class json file.
   * The class name is passed as a parameter, the file path is derived from it
   * internally via strtolower. It returns false when the id is already taken.
   * The method deliberately bypasses the constructor — this way user instances
   * for example cannot be created "empty-new" (getUser fallback).
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
    if( BaseObject::existsObject( $strClassName, $strId ) ) return false;

    $strFile    = BaseObject::FILEPATHJSON . 'data/' . strtolower( $strClassName ) . 's.json';
    $objObjects = BaseObject::loadFileDeCrypted( $strFile );

    if( ! isset( $objObjects ) ) $objObjects = new stdClass();

    $objObjects->{ $strId } = $objEntry;

    BaseObject::saveFileEnCrypted( $strFile, $objObjects );

    return true;
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
    if( ! file_exists( BaseObject::FILEPATHJSON . 'data/' . strtolower( $strClassName ) . 's.json' ) ) return null;

    $objRawObjects = BaseObject::loadFileDeCrypted( BaseObject::FILEPATHJSON . 'data/' . strtolower( $strClassName ) . 's.json' );

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
    if( ! file_exists( BaseObject::FILEPATHJSON . 'data/' . strtolower( $strClassName ) . 's.json' ) ) return null;

    $objRawObjects = BaseObject::loadFileDeCrypted( BaseObject::FILEPATHJSON . 'data/' . strtolower( $strClassName ) . 's.json' );

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

    foreach( $objRawObjects as $strId => $objRawObject ) {
      array_push( $arrObjects, new $strClassName( $strId ) );
    }

    return $arrObjects;
  }

  /**
   * This method returns the default app configuration (configs.json).
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
   * This method reads and decodes a json file. It auto-detects whether the
   * content is plain JSON (local/dev) or encrypted (production) before
   * decrypting. Line breaks are stripped upfront so that encrypted content
   * can be detected safely.
   *
   * @param     string           $strFile   The file path to load
   * @return    object|array     The decoded file content
   *
   * @example   $objContent = BaseObject::loadFileDeCrypted( $strFile );
   *
   */
  public static function loadFileDeCrypted( string $strFile ) : object | array {
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

}

// EOF
