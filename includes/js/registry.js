/**
 * This class is the instance layer of the frontend: a map of maps —
 * class name (lower case) → (id → instance). The Loader registers the
 * finished instances, templates never read them directly but wait for
 * the registry events (ready per id, class-wide per instance, appReady
 * once) — already fired events replay for late listeners, class-wide
 * listeners subscribe permanently, the timing stays decoupled from
 * the load order.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-17
 *
 * @example   const user = window[ appAlias ].registry.get( 'user' );
 *
 */
class Registry {

/**
 * The event marker of the one-time app-ready state — the Loader sets
 * it after the complete bootstrap, later onAppReady listeners get the
 * app instance replayed from it.
 *
 * @static
 *
 * @type      {string}
 *
 * @example   if( this.firedEvents[ Registry.EVENTNAMEAPPREADY ] ) return;
 *
 */
  static EVENTNAMEAPPREADY = '__app_ready__';

/**
 * The blueprint id of the viewNewObject route (contract with
 * BaseObject::createBlueprint): getAll delivers only real instances —
 * the direct blueprint access runs over get( className, 'new' )
 * resp. onReady.
 *
 * @static
 *
 * @type      {string}
 *
 * @example   if( id === Registry.BLUEPRINTID ) return;
 *
 */
  static BLUEPRINTID = 'new';

/**
 * This method is the constructor of the class: it carries the registry
 * map (class name → instance map), the ready listeners and the fired
 * event store (replay base — class-wide ready events hold an instance
 * map instead of a single value).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const registry = new Registry();
 *
 */
  constructor() {
    this._registry   = new Map();
    this.listeners   = {};
    this.firedEvents = {};

    return;
  }

/**
 * This method registers an instance under class name and id and fires
 * the ready events in order: the instance event ready:{class}:{id}
 * first, afterwards the class-wide event ready:{class} for every
 * class-wide listener (blueprints keep silent class-wide — the
 * blueprint onReady path stays the dedicated route). Earlier
 * registered listeners dispatch directly, later ones are served from
 * the replay over firedEvents.
 *
 * @public
 *
 * @param     {string}   className    The PHP class name of the envelope
 * @param     {string}   id            The instance id (registry key)
 * @param     {object}   instance      The finished instance
 * @return    {void}
 *
 * @example   registry.register( 'User', 'max@musterman.de', instance );
 *
 */
  register( className, id, instance ) {
    const classKey = this._key( className );

    if( ! this._registry.has( classKey ) ) this._registry.set( classKey, new Map() );

    this._registry.get( classKey ).set( id, instance );

    const eventNameInstance = this.eventNameReady( classKey, id );
    this.firedEvents[ eventNameInstance ] = instance;
    if( this.listeners[ eventNameInstance ] ) this._dispatchSafely( instance, ...this.listeners[ eventNameInstance ] );

    if( id === this.constructor.BLUEPRINTID ) return;

    const eventNameClass = this.eventNameReady( classKey, null );
    if( ! this.firedEvents[ eventNameClass ] ) this.firedEvents[ eventNameClass ] = new Map();
    this.firedEvents[ eventNameClass ].set( id, instance );
    if( this.listeners[ eventNameClass ] ) this._dispatchSafely( instance, ...this.listeners[ eventNameClass ] );

    return;
  }

/**
 * This method returns one instance: with id exactly the addressed
 * entry, without id the first instance of the class (single-instance
 * case like the login user). Missing class or id deliver undefined.
 *
 * @public
 *
 * @param     {string}         className   The PHP class name
 * @param     {string|null}   id          The instance id or null for the first instance
 * @return    {object}         instance    The instance or undefined
 *
 * @example   const user = registry.get( 'user' );
 *
 */
  get( className, id = null ) {
    const classKey = this._key( className );

    if( ! this._registry.has( classKey ) ) return undefined;

    if( id === null ) {
      const classMap = this._registry.get( classKey );

      return classMap.values().next().value;
    }

    return this._registry.get( classKey ).get( id );
  }

/**
 * This method returns a map copy of all real instances of a class:
 * blueprints (id BLUEPRINTID) are filtered out — templates can drive
 * card loops without a skip line, size counts only real instances.
 *
 * @public
 *
 * @param     {string}   className    The PHP class name
 * @return    {Map}      instances    Map copy without blueprints (id → instance)
 *
 * @example   for( const [ key, instance ] of registry.getAll( 'User' ) ) { ... }
 *
 */
  getAll( className ) {
    const classKey = this._key( className );
    const classMap = this._registry.get( classKey );

    if( ! classMap ) return new Map();

    const realInstances = new Map();

    classMap.forEach( ( instance, id ) => {
      if( id === this.constructor.BLUEPRINTID ) return;

      realInstances.set( id, instance );
    } );

    return realInstances;
  }

/**
 * This method checks whether the registry holds an instance under
 * class name and id.
 *
 * @public
 *
 * @param     {string}    className   The PHP class name
 * @param     {string}    id          The instance id
 * @return    {boolean}   exists      True when the entry exists
 *
 * @example   if( registry.has( 'user', 'new' ) ) { ... }
 *
 */
  has( className, id ) {
    const classKey = this._key( className );

    return this._registry.has( classKey ) && this._registry.get( classKey ).has( id );
  }

/**
 * This method walks every registered instance of every class —
 * blueprints included.
 *
 * @public
 *
 * @param     {function}   callback   The callback per instance: ( instance, id, className )
 * @return    {void}
 *
 * @example   registry.forEachAllInstance( ( instance, id, className ) => { ... } );
 *
 */
  forEachAllInstance( callback ) {
    this._registry.forEach( ( classMap, className ) => {
      classMap.forEach( (instance, id ) => {
        callback( instance, id, className );
      } );
    } );

    return;
  }

/**
 * This method walks every registered class name.
 *
 * @public
 *
 * @param     {function}   callback   The callback per class: ( className )
 * @return    {void}
 *
 * @example   registry.forEachClass( ( className ) => { ... } );
 *
 */
  forEachClass( callback ) {
    this._registry.forEach( ( classMap, className ) => {
      callback( className );
    } );

    return;
  }

/**
 * This method registers a ready listener in two modes: with id an
 * one-time delivery — an already fired event dispatches the callback
 * directly (replay), the callback is not stored; with id null a
 * permanent per-instance subscription of the class — the replay
 * dispatches the callback for every already registered instance, the
 * callback stays armed for later registrations (e. g. on-demand
 * envelopes after appReady). Blueprints never arrive class-wide:
 * templates which need them listen on onReady( className, 'new' ).
 *
 * @public
 *
 * @param     {string}        className   The PHP class name
 * @param     {string|null}   id          The instance id or null for the class-wide subscription
 * @param     {function}      callback    The callback with the instance as parameter
 * @return    {void}
 *
 * @example   registry.onReady( 'User', 'new', ( blueprint ) => { ... } );
 * @example   registry.onReady( 'User', null, ( instance ) => { ... } );
 *
 */
  onReady( className, id = null, callback ) {
    const eventName = this.eventNameReady( className, id );

    if( id === null ) {
      if( this.firedEvents[ eventName ] ) this.firedEvents[ eventName ].forEach( ( instance ) => this._dispatchSafely( instance, callback ) );

      if( ! this.listeners[ eventName ] ) this.listeners[ eventName ] = [];

      this.listeners[ eventName ].push( callback );

      return;
    }

    if( this.firedEvents[ eventName ] ) {
      this._dispatchSafely( this.firedEvents[ eventName ], callback );

      return;
    }

    if( ! this.listeners[ eventName ] ) this.listeners[ eventName ] = [];

    this.listeners[ eventName ].push( callback );

    return;
  }

/**
 * This method builds the ready event name: ready:{class} or
 * ready:{class}:{id} — the class part is lower case.
 *
 * @public
 *
 * @param     {string}   className   The PHP class name
 * @param     {string}   id          The instance id
 * @return    {string}   eventName   The event name
 *
 * @example   const eventName = registry.eventNameReady( 'User', 'max@musterman.de' );
 *
 */
  eventNameReady( className, id ) {
    const classKey = this._key( className );

    return id ? 'ready:' + classKey + ':' + id : 'ready:' + classKey;
  }

/**
 * This method marks the app as ready — the Loader calls it once after
 * the complete bootstrap (all resources awaited, all instances
 * registered) and dispatches the stored onAppReady callbacks with the
 * app instance.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   registry.setAppReady();
 *
 */
  setAppReady() {
    const eventNameReady               = this.constructor.EVENTNAMEAPPREADY;
    this.firedEvents[ eventNameReady ] = true;

    if( this.listeners[ eventNameReady ] ) this._dispatchSafely( this.get( 'app' ), ...this.listeners[ eventNameReady ] );

    return;
  }

/**
 * This method registers an onAppReady callback: already fired events
 * dispatch directly with the app instance (replay), otherwise the
 * callback waits for the Loader. Templates use this as the entry
 * hook instead of running directly after the instantiation.
 *
 * @public
 *
 * @param     {function}   callback   The callback with the app instance as parameter
 * @return    {void}
 *
 * @example   registry.onAppReady( ( app ) => { app.registry.getAll( 'User' ); } );
 *
 */
  onAppReady( callback ) {
    const eventNameReady = this.constructor.EVENTNAMEAPPREADY;

    if( this.firedEvents[ eventNameReady ] ) {
      this._dispatchSafely( this.get( 'app' ), callback );
      return;
    }

    if( ! this.listeners[ eventNameReady ] ) this.listeners[ eventNameReady ] = [];

    this.listeners[ eventNameReady ].push( callback );

    return;
  }

/**
 * This method encapsulates all listener dispatches: the first
 * callback exception no longer aborts the remaining listeners
 * (sister risk) — the display runs over the error channel of the
 * app (when already registered).
 *
 * @private
 *
 * @param     {object}     instance        The instance dispatched to the callbacks
 * @param     {function}   arrCallbacks    The callbacks to dispatch
 * @return    {void}
 *
 * @example   this._dispatchSafely( app, ...this.listeners[ eventName ] );
 *
 */
  _dispatchSafely( instance, ...arrCallbacks ) {
    for( let i = 0; i < arrCallbacks.length; i++ ) {
      try {
        arrCallbacks[ i ]( instance );
      } catch( runtimeError ) {
        console.error( '[Fehlerkanal JS] Registry-Callback-Exception:', runtimeError );

        const app = this.get( 'app' );

        if( app ) app.reportError( runtimeError, 'registry callback' );
      }
    }

    return;
  }

/**
 * This method normalizes a class name to the registry key: lower case
 * and trimmed — the PHP class names arrive with a capital first
 * letter.
 *
 * @private
 *
 * @param     {string}   className   The class name
 * @return    {string}   classKey    The normalized key
 *
 * @example   const key = registry._key( 'User' );
 *
 */
  _key( className ) {
    return ! className ? '' : className.toLowerCase().trim();
  }

}
