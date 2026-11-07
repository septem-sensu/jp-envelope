/**
 * This class is the dynamic half of the frontend bootstrap: the
 * header loads only the base scripts statically (utils, loader,
 * registry, fieldValidator, BaseObject) — every view class, Chart.js and
 * every stylesheet is requested by the Loader on demand (one class
 * per file, file name = first letter lower case CamelCase).
 * registerObjects() builds the complete bootstrap from the injected
 * collector: per envelope one instance, then all resources awaited,
 * then the registry entries, then the batch fill (fillObjects), then
 * the deferred event flush and app-ready — all-or-nothing; only
 * without debug the rawObjects leave the namespace, a failed
 * resource load keeps them (fail-loud). Every route registers with
 * its event type (registerObjects: load, loadNewObject: new,
 * loadObject: load, saveObject: save, deleteObject: delete) — the
 * registry delivers the matching object events beside ready, deferred
 * via fireEvents() after the fill so every reference of the batch is
 * resolved before a listener sees the instance.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.19
 * @since     2026-10-16
 *
 * @example   const loader = new Loader();
 *
 */
class Loader extends EventTarget {

/**
 * This method is the constructor of the class: it carries the
 * promise dedupe map for script and stylesheet loads (key: file
 * name).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const loader = new Loader();
 *
 */
  constructor() {
    super();

    this.promises    = {};

    return;
  }

/**
 * This method builds the complete app bootstrap: it instantiates
 * every envelope of the collector in parallel, awaits afterwards all
 * loadResources() calls, registers the finished instances in the
 * registry, fills the batch references, flushes the deferred event
 * queue (fireEvents) and fires app-ready only then. Without debug it
 * deletes the rawObjects from the namespace afterwards — a failed
 * resource load keeps them (fail-loud).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   loader.registerObjects();
 *
 */
  registerObjects() {
    const rawObjects = window[ appAlias ].rawObjects;
    const promises   = [];

    for( let i = 0; i < rawObjects.length; i++ ) {
      promises.push( this.createInstance( rawObjects[ i ] ) );
    }

    Promise.all( promises ).then( async ( instances ) => {
      const resourcePromises = [];

      for( let i = 0; i < instances.length; i++ ) {
        resourcePromises.push( instances[ i ].loadResources() );
      }

      try {
        await Promise.all( resourcePromises );

        for( let i = 0; i < instances.length; i++ ) {
          window[ appAlias ].registry.register( rawObjects[ i ].class, rawObjects[ i ].id, instances[ i ], 'load' );
        }

        for( let i = 0; i < instances.length; i++ ) {
          instances[ i ].fillObjects();
        }

        window[ appAlias ].registry.fireEvents();

        if( ! window[ appAlias ].config.debug ) delete window[ appAlias ].rawObjects;

        window[ appAlias ].registry.setAppReady();

      } catch ( resourceError ) {
        console.error( "Fehler beim Laden der Objekt-Ressourcen (Bibliotheken):", resourceError );
      }

      return;
    } ).catch( ( error ) => {
      console.error( "Ein Fehler ist beim Laden der Klassen aufgetreten:", error );
    } );

    return;
  }

/**
 * This method is phase 1 of the envelope pipeline: load the class
 * file (promise dedupe), instantiate the class and copy the envelope
 * data via fillObject(). It does not register yet — waiting for the
 * resources and the registry entry belong to the caller
 * (registerObjects) resp. instantiateRawObject.
 *
 * @public
 *
 * @param     {object}    rawObject   The envelope (class, id, data) from the collector
 * @return    {Promise}   instance    The data carrier instance without loaded resources
 *
 * @example   const instance = await loader.createInstance( rawObject );
 *
 */
  async createInstance( rawObject ) {
    await this.loadClassFileAsync( rawObject );

    const instance = new window[ rawObject.class ]();

    instance.data  = rawObject.data;

    instance.fillObject();

    return instance;
  }

/**
 * This method runs the envelope pipeline for a single on-demand
 * loaded object: instantiate, load resources, register in the
 * registry. The event delivery is deferred — the registration only
 * queues in the registry (pendingEvents), the caller flushes via
 * fireEvents() when the batch is complete (instantiateRawObjects).
 *
 * @public
 *
 * @param     {object}        rawObject    The envelope (class, id, data)
 * @param     {string|null}   eventName    The event type of the registration ('load', 'new', 'save', 'delete') or null for ready only
 * @return    {Promise}       instance     The registered instance
 *
 * @example   const blueprint = await loader.instantiateRawObject( rawObject, 'new' );
 *
 * @see Loader#instantiateRawObjects
 *
 */
  async instantiateRawObject( rawObject, eventName = null ) {
    if( ! rawObject.class || ! rawObject.id ) throw new Error( 'Envelope ohne Instanz-Daten: ' + JSON.stringify( rawObject ) );

    const instance = await this.createInstance( rawObject );

    await instance.loadResources();

    window[ appAlias ].registry.register( rawObject.class, rawObject.id, instance, eventName );

    return instance;
  }

/**
 * This method runs the complete envelope pipeline for a whole envelope
 * array in collector order: every envelope is instantiated and registered
 * via instantiateRawObject, then the sub object references of all
 * instances are resolved via fillObjects(), then the deferred event
 * queue flushes via fireEvents() — the object events fire only when
 * every reference of the batch is resolved. Use this variant for the
 * json views that deliver an array of envelopes (blueprint,
 * viewLoadObject).
 *
 * @public
 *
 * @param     {object}         rawObjects   The envelope array (class, id, data) from the collector
 * @param     {string|null}    eventName    The event type of the registrations ('load', 'new', 'save', 'delete') or null for ready only
 * @return    {Promise}        instances    The registered instances in collector order
 *
 * @example   const instances = await loader.instantiateRawObjects( rawObjects, 'save' );
 *
 * @see Loader#instantiateRawObject
 *
 */
  async instantiateRawObjects( rawObjects, eventName = null ) {
    const instances = [];

    for( let i = 0; i < rawObjects.length; i++ ) {
      if( ! rawObjects[ i ].class || ! rawObjects[ i ].id ) throw new Error( 'Envelope ohne Instanz-Daten: ' + JSON.stringify( rawObjects[ i ] ) );

      const instance = await this.instantiateRawObject( rawObjects[ i ], eventName );

      instances.push( instance );
    }

    for( let i = 0; i < instances.length; i++ ) {
      instances[ i ].fillObjects();
    }

    window[ appAlias ].registry.fireEvents();

    return instances;

  }

/**
 * This method loads a blueprint on demand (called by app.newObject):
 * fetch on the JSON view viewNewObject, the envelope runs through the
 * standard pipeline and registers with the 'new' event type (onNew
 * beside ready) — the class file can load for the first time on
 * this way. Errors (HTML answer at a wrong role, empty 500 body,
 * network) tear the promise up, the catch sits in the app wrapper
 * (overlay).
 *
 * @public
 *
 * @param     {string}    newClass    The PHP class name of the blueprint
 * @return    {Promise}   instance    The registered blueprint instance (id 'new')
 *
 * @example   const blueprint = await loader.loadNewObject( 'User' );
 *
 */
  async loadNewObject( newClass ) {
    // pathname instead of href: old query parameters of the current view (e.g. graphAction) must not travel along
    const url      = document.location.pathname + '?view=viewNewObject&newClass=' + encodeURIComponent( newClass );
    const response = await fetch( url );

    return await this.instantiateRawObjects( await response.json(), 'new' );
  }

/**
 * This method loads a object on demand (called by app.loadObject):
 * fetch on the JSON view viewLoadObject, the envelope runs through the
 * standard pipeline and registers with the 'load' event type (onLoad
 * beside ready) — the class file can load for the first time on
 * this way. Errors (HTML answer at a wrong role, empty 500 body,
 * network) tear the promise up, the catch sits in the app wrapper
 * (overlay).
 *
 * @public
 *
 * @param     {string}    className   The PHP class name of the object
 * @param     {string}    id          The PHP od of the object
 * @return    {Promise}   instance    The registered object instance
 *
 * @example   const user = await loader.loadObject( 'User', 'max@musterman.de' );
 *
 */
  async loadObject( className, id ) {
    const url      = document.location.pathname + '?view=viewLoadObject&class=' + encodeURIComponent( className ) + '&id=' + encodeURIComponent( id );
    const response = await fetch( url );

    return await this.instantiateRawObjects( await response.json(), 'load' );
  }

/**
 * This method saves an envelope over the generic json save route
 * (viewSaveObject, POST): the envelope (class, id, data) travels as the
 * urlencoded 'object' parameter — with the blueprint id the server
 * creates the object under a generated id. The response envelopes run
 * through the standard pipeline (instantiateRawObjects) and register
 * with the 'save' event type (onSave beside ready): object envelopes
 * register/refresh their registry instances, AppError
 * envelopes display in the overlay afterwards. On a successful
 * blueprint save the stale blueprint instance leaves the registry —
 * the server id replaced it. Errors (HTML answer at a wrong role,
 * empty 500 body, network) tear the promise up, the catch sits in the
 * app wrapper (overlay).
 *
 * @public
 *
 * @param     {object}    rawObject        The envelope (class, id, data) to save
 * @return    {Promise}   instances        The registered instances of the response (saved object or AppError cards)
 *
 * @example   await loader.saveObject( { class: 'User', id: 'new', data: { ... } } );
 *
 */
  async saveObject( rawObject ) {
    const url  = document.location.pathname + '?view=viewSaveObject';
    const body = new URLSearchParams();

    body.set( 'object', JSON.stringify( rawObject ) );

    const response = await fetch( url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body:    body
    } );

    const instances = await this.instantiateRawObjects( await response.json(), 'save' );

    let boolHasErrors = false;

    for( let i = 0; i < instances.length; i++ ) {
      if( instances[ i ] instanceof window.AppError ) {
        boolHasErrors = true;

        instances[ i ].showError();
      }
    }

    if( ! boolHasErrors && rawObject.id === window[ appAlias ].registry.constructor.BLUEPRINTID ) {
      window[ appAlias ].registry.remove( rawObject.class, window[ appAlias ].registry.constructor.BLUEPRINTID );
    }

    return instances;
  }

/**
 * This method deletes an addressed object over the generic json delete
 * route (viewDeleteObject, POST): the parameters class and id travel as
 * urlencoded body. The success response carries the deleted envelope
 * (plus reference sub envelopes) — instantiateRawObjects registers it
 * with the 'delete' event type (onDelete beside ready), then remove
 * cleans every replay trace of the id, so the delete events fire on a
 * registered instance. AppError envelopes display in the overlay and
 * leave the registry untouched (a blocked delete keeps its instance).
 * Errors (HTML answer at a wrong role, empty 500 body, network) tear
 * the promise up, the catch sits in the app wrapper (overlay).
 *
 * @public
 *
 * @param     {string}    className   The PHP class name of the object
 * @param     {string}    id          The id of the object
 * @return    {Promise}   instances   The registered instances of the response (deleted instance on success, AppError cards on rejection)
 *
 * @example   await loader.deleteObject( 'User', 'max@musterman.de' );
 *
 */
  async deleteObject( className, id ) {
    const url  = document.location.pathname + '?view=viewDeleteObject';
    const body = new URLSearchParams();

    body.set( 'class', className );
    body.set( 'id', id );

    const response = await fetch( url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body:    body
    } );

    const instances = await this.instantiateRawObjects( await response.json(), 'delete' );

    let boolHasErrors = false;

    for( let i = 0; i < instances.length; i++ ) {
      if( instances[ i ] instanceof window.AppError ) {
        boolHasErrors = true;

        instances[ i ].showError();
      }
    }

    if( ! boolHasErrors ) window[ appAlias ].registry.remove( className, id );

    return instances;
  }

/**
 * This method ensures that the class file of an envelope exists in
 * the window namespace: already loaded classes resolve immediately
 * (constructor check on window), otherwise the Javascript file
 * (firstLetterToLowerCase class name) loads on demand — the promise
 * dedupe of loadScriptAsync prevents parallel double loads.
 *
 * @public
 *
 * @param     {object}    rawObject   The envelope with the class property
 * @return    {Promise}   promise     The loading promise of the class file
 *
 * @example   await loader.loadClassFileAsync( rawObject );
 *
 */
  loadClassFileAsync( rawObject ) {
    if( ! rawObject.class ) return Promise.resolve();

    const className = rawObject.class;
    const key       = Utils.firstLetterToLowerCase( className );
    const fileName  = key + '.js';

    if( typeof window[ className ] === 'function' ) return Promise.resolve();

    return this.loadScriptAsync( fileName );
  }

/**
 * This method appends a Javascript file into the document head and
 * dedupes over the promises property: parallel calls share the same
 * promise per file name. The URL carries the ?v= cache buster from
 * the config version.
 *
 * @public
 *
 * @param     {string}   scriptName   The file name of the Javascript file
 * @return    {Promise}  promise      The loading promise of the script
 *
 * @example   await loader.loadScriptAsync( 'user.js' );
 *
 */
  loadScriptAsync( scriptName ) {
    if( this.promises[ scriptName ] ) return this.promises[ scriptName ];

    this.promises[ scriptName ] = new Promise( ( resolve, reject ) => {
      const script = document.createElement('script');

      script.type  = 'text/javascript';
      script.src   = window[ appAlias ].config.jsPath + scriptName + '?v=' + window[ appAlias ].config.version;

      script.setAttribute( 'data-script', scriptName );

      script.addEventListener( 'load', () => { resolve( true ); } );
      script.addEventListener( 'error', () => { reject( new Error( 'Fehler beim Laden des Skripts: ' + scriptName ) ); } );

      document.querySelector('head').append(script);
    } );

    return this.promises[ scriptName ];
  }

/**
 * This method appends a stylesheet file into the document head and
 * dedupes over the promises property — same principle as
 * loadScriptAsync, per file name. The URL carries the ?v= cache
 * buster from the config version.
 *
 * @public
 *
 * @param     {string}   fileName   The file name of the stylesheet
 * @return    {Promise}  promise    The loading promise of the stylesheet
 *
 * @example   await loader.loadStylesheetAsync( 'user.css' );
 *
 */
  loadStylesheetAsync( fileName ) {
    if( this.promises[ fileName ] ) return this.promises[ fileName ];

    this.promises[ fileName ] = new Promise( ( resolve, reject ) => {
      const link = document.createElement( 'link' );

      link.rel   = 'stylesheet';
      link.href  = window[ appAlias ].config.cssPath + fileName + '?v=' + window[ appAlias ].config.version;

      document.querySelector( 'head' ).append( link );

      link.addEventListener( 'load', () => { resolve( true ); } );
      link.addEventListener( 'error', () => { reject( new Error( 'Fehler beim Laden des Stylesheets: ' + fileName ) ); } );

      return;
    } );

    return this.promises[ fileName ];
  }

}
