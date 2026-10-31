/**
 * This class is the dynamic half of the frontend bootstrap: the
 * header loads only the base scripts statically (utils, loader,
 * registry, fieldValidator, BaseObject) — every view class, Chart.js and
 * every stylesheet is requested by the Loader on demand (one class
 * per file, file name = first letter lower case CamelCase).
 * registerObjects() builds the complete bootstrap from the injected
 * collector: per envelope one instance, then all resources awaited,
 * then the registry entries, then app-ready — all-or-nothing; only
 * without debug the rawObjects leave the namespace, a failed
 * resource load keeps them (fail-loud).
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
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
 * registry and fires app-ready only then. Without debug it deletes
 * the rawObjects from the namespace afterwards — a failed resource
 * load keeps them (fail-loud).
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
          window[ appAlias ].registry.register( rawObjects[ i ].class, rawObjects[ i ].id, instances[ i ] );
        }

        for( let i = 0; i < instances.length; i++ ) {
          instances[ i ].fillObjects();
        }

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
 * This method runs the complete envelope pipeline for on-demand
 * loaded objects (blueprints): instantiate, load resources,
 * register in the registry — the onReady event incl. replay fires
 * the registry itself.
 *
 * @public
 *
 * @param     {object}    rawObject   The envelope (class, id, data)
 * @return    {Promise}   instance    The registered instance
 *
 * @example   const blueprint = await loader.instantiateRawObject( rawObject );
 *
 */
  async instantiateRawObject( rawObject ) {
    if( ! rawObject.class || ! rawObject.id ) throw new Error( 'Envelope ohne Instanz-Daten: ' + JSON.stringify( rawObject ) );

    const instance = await this.createInstance( rawObject );

    await instance.loadResources();

    window[ appAlias ].registry.register( rawObject.class, rawObject.id, instance );

    return instance;
  }

/**
 * This method runs the complete envelope pipeline for a whole envelope
 * array in collector order: every envelope is instantiated and registered
 * via instantiateRawObject, then the sub object references of all
 * instances are resolved via fillObjects(). Use this variant for the json
 * views that deliver an array of envelopes (blueprint, viewLoadObject).
 *
 * @public
 *
 * @param     {object}     rawObjects   The envelope array (class, id, data) from the collector
 * @return    {Promise}    instances    The registered instances in collector order
 *
 * @example   const instances = await loader.instantiateRawObjects( rawObjects );
 *
 * @see Loader#instantiateRawObject
 *
 */
  async instantiateRawObjects( rawObjects ) {
    const instances = [];

    for( let i = 0; i < rawObjects.length; i++ ) {
      if( ! rawObjects[ i ].class || ! rawObjects[ i ].id ) throw new Error( 'Envelope ohne Instanz-Daten: ' + JSON.stringify( rawObjects[ i ] ) );

      const instance = await this.instantiateRawObject( rawObjects[ i ] );

      instances.push( instance );
    }

    for( let i = 0; i < instances.length; i++ ) {
      instances[ i ].fillObjects();
    }

    return instances;

  }

/**
 * This method loads a blueprint on demand (called by app.newObject):
 * fetch on the JSON view viewNewObject, the envelope runs through the
 * standard pipeline — the class file can load for the first time on
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

    return await this.instantiateRawObjects( await response.json() );
  }

/**
 * This method loads a object on demand (called by app.loadObject):
 * fetch on the JSON view viewLoadObject, the envelope runs through the
 * standard pipeline — the class file can load for the first time on
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

    return await this.instantiateRawObjects( await response.json() );
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
