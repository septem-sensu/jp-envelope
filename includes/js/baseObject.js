/**
 * This class is the JavaScript base class of every business class: it
 * carries the envelope data (fillObject), delivers the default
 * getter/setter pair, clones templates with suffixed DOM ids into the
 * page (multi-instance cards), owns the default resource loader and
 * hosts the global click/Enter delegation for the CRUD forms as well
 * as the persistent overlay of the JS error channel. The server
 * counterpart is the PHP BaseObject — every business object arrives as
 * envelope (class, id, data) through the collector and the Loader
 * instantiates it from this family.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-15
 *
 * @example   const baseObject = new BaseObject();
 *
 */
class BaseObject extends EventTarget {

/**
 * This method is the constructor of the class: it wires the own
 * fieldValidator, the global loader/config references, the debug flag and
 * registers the global event handlers exactly once per page load.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const baseObject = new BaseObject();
 *
 */
  constructor() {
    super();

    this.fieldValidator           = new FieldValidator();
    this.loader                   = window[ appAlias ].loader;
    this.config                   = window[ appAlias ].config;
    this.appAlias                 = appAlias;
    this.debug                    = this.config.debug ? true : false;
    this.registeredEventHandler   = {};

    this.registerEventHandler();

    return;
  }

/**
 * This method is the default getter of the class.
 *
 * @public
 *
 * @param     {string}   property   The property of the value
 * @return    {*}        value      The value of the property
 *
 * @example   const value = instance.get( 'formId' );
 *
 * @see BaseObject#set
 *
 */
  get( property ) {
    return this[ property ];
  }

/**
 * This method is the default setter of the class.
 *
 * @public
 *
 * @param     {string}   property   The property that you want to set
 * @param     {*}        value      The value you want to set to the property
 * @return    {void}
 *
 * @example   instance.set( 'formId', 'user-form' );
 *
 * @see BaseObject#get
 *
 */
  set( property, value ) {
    this[ property ] = value;

    return;
  }

/**
 * This method copies the data payload of the envelope (this.data) onto
 * own properties: properties that are already set keep their value
 * (constructor properties survive), the rest is filled from the data.
 * The data carrier is deleted afterwards.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.fillObject();
 *
 */
  fillObject() {
    if( typeof this.data !== 'object' || this.data == null ) return;

    for( const property in this.data ) {
      if( this[ property ] ) continue;

      this[ property ] = this.data[ property ];
    }

    delete this.data;

    return;
  }

/**
 * This method resolves the loose sub object references of the instance:
 * every property marked with propertyType 'object' is replaced by the
 * matching instance from the registry. It runs after the registry
 * registration, so referenced objects of the same envelope batch are
 * already resolvable.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.fillObjects();
 *
 * @see BaseObject#fillObject
 *
 */
  fillObjects() {
    for( const property in this ) {
      if( typeof this[ property ] !== 'object' || this[ property ] === null ) continue;
      if( typeof this[ property ].propertyType !== 'string' || this[ property ].propertyType === '' ) continue;

      if( this[ property ].propertyType === 'object' ) {
        this[ property ] = window[ appAlias ].registry.get( this[ property ].class, this[ property ].value );
      }

      if( this[ property ].propertyType === 'collection' ) {

      }
    }

    return;
  }

/**
 * This method is the default resource loader: it resolves immediately
 * — the resource readiness travels only via the loadResources promise
 * (the Loader awaits it) and the registry ready events. Classes with
 * own resources (Chart.js, stylesheets) overwrite it and await their
 * own loads — the Loader calls it after the instantiation and awaits
 * it before the instance registers.
 *
 * @public
 *
 * @return    {Promise<void>}
 *
 * @example   await instance.loadResources();
 *
 */
  async loadResources() {
    return;
  }

/**
 * This method registers the global event handlers of the class exactly
 * once per page load (guard via Utils.globalRegisteredEvents): the
 * click delegation for submit and CRUD buttons (save, delete, new),
 * the Enter key delegation on input fields and the PWA install
 * prompt. After DOM readiness it resets marked forms.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.registerEventHandler();
 *
 */
  registerEventHandler() {
    if( Utils.globalRegisteredEvents[ 'BaseObject' ] ) return;
    Utils.globalRegisteredEvents[ 'BaseObject' ] = true;

    document.addEventListener( 'click', ( event ) => {
      const submitButton = event.target.closest( 'button.submit' );

      if( submitButton != null ) {
        if( submitButton.hasAttribute( 'disabled' ) ) return;

        const form = submitButton.closest( 'form' );

        form.setAttribute( 'method', 'POST' );
        form.submit();

        return;
      }

      const crudButton = event.target.closest( 'button.save, button.delete, button.new' );

      if( crudButton != null ) {
        if( crudButton.hasAttribute( 'disabled' ) ) return;

        const form = crudButton.closest( 'form' );

        this.crudPostObject( form.getAttribute( 'id' ), crudButton.getAttribute( 'data-action' ) );

        return;
      }

      return;
    } );

    document.addEventListener( 'keydown', ( event ) => {
      if( event.key !== 'Enter' ) return;
      if( event.target.tagName.toLowerCase() !== 'input' ) return;

      event.preventDefault();

      const form = event.target.closest( 'form' );

      if( form == null ) return;

      const submitButton = form.querySelector( 'button.submit' );
      const saveButton   = form.querySelector( 'button.save' );
      const newButton    = form.querySelector( 'button.new' );

      if( submitButton != null ) {
        submitButton.click();
      } else if( saveButton != null ) {
        saveButton.click();
      } else if( newButton != null ) {
        newButton.click();
      }

      return;
    } );

    window.addEventListener( 'beforeinstallprompt', ( event ) => {
      event.preventDefault();
      this.activateInstallAppButton( event );

      return;
    } );

    if( document.readyState === 'loading' ) {
      document.addEventListener( 'DOMContentLoaded', () => {
        this.resetForms();

        return;
      } );

      return;
    }

    this.resetForms();

    return;
  }

/**
 * This method resets every form that carries a hidden resetForm input
 * with value 1 — the server marks forms this way that should start
 * empty after a save.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.resetForms();
 *
 */
  resetForms() {
    const resetInputs = document.querySelectorAll( 'input[name="resetForm"]' );

    for( let i = 0; i < resetInputs.length; i++ ) {
      if( resetInputs[ i ].value !== '1' ) continue;

      resetInputs[ i ].closest( 'form' ).reset();
    }

    return;
  }

/**
 * This method submits a form as POST request to the current URL: it
 * writes the action into the hidden action input and lets the browser
 * send the form (full page reload — actions answer with HTML views).
 *
 * @public
 *
 * @param     {string}   formId   The DOM id of the form
 * @param     {string}   action   The action name (data-action of the trigger button)
 * @return    {void}
 *
 * @example   instance.crudPostObject( 'user-form', 'User::saveUser' );
 *
 */
  crudPostObject( formId, action ) {
    const form         = document.querySelector( '#' + formId );
    const href         = document.location.href;

    form.setAttribute( 'method', 'POST' );
    form.setAttribute( 'action', href );
    form.querySelector( 'input[name="action"]' ).value = action;

    form.submit();

    return;
  }

/**
 * This method activates the app install buttons when the browser
 * offers a PWA install prompt: it enables the buttons, unhides them
 * and routes their clicks to the prompt of the install event.
 *
 * @public
 *
 * @param     {object}   installEvent   The beforeinstallprompt event with the prompt() method
 * @return    {void}
 *
 * @example   instance.activateInstallAppButton( installEvent );
 *
 */
  activateInstallAppButton( installEvent ) {
    const appInstallButtons = document.querySelectorAll( '.app-install-button' );

    for( let i = 0; i < appInstallButtons.length; i++ ) {
      appInstallButtons[ i ].removeAttribute( 'disabled' );
      appInstallButtons[ i ].classList.remove( 'display-hidden' );
      appInstallButtons[ i ].addEventListener( 'click', ( event ) => {
        installEvent.prompt();

        return;
      } );
    }

    return;
  }

/**
 * This method clones the content of a template element into the target
 * container. With setId it suffixes every id and for attribute with
 * '-' + validateId( this.id ) — parallel instance cards stay
 * addressable over unique DOM ids (CSS selectors). The template
 * content lives in an own document fragment: DOM queries must run
 * after this append, not before.
 *
 * @public
 *
 * @param     {string}    templateId   The DOM id of the template element
 * @param     {string}    targetId     The DOM id of the target container
 * @param     {boolean}   setId        True to suffix the ids (default true)
 * @return    {void}
 *
 * @example   instance.appendTemplate( 'user-form-template', 'user-cards', true );
 *
 */
  appendTemplate( templateId, targetId, setId = true ) {
    const targetContainer = document.querySelector( '#' + targetId );
    const template        = document.querySelector( '#' + templateId );

    if( template === null || targetContainer === null ) return;

    const templateClone   = template.content.cloneNode( true );
    const idTags          = templateClone.querySelectorAll( '[id]' );
    const forTags         = templateClone.querySelectorAll( '[for]' );
    const registry        = window[ appAlias ].registry;
    const app             = registry.get( 'app' );
    const currentRoleId   = app.currentUserRoleId;

    if( setId ) {
      for( let i = 0; i < idTags.length; i++ ) {
        idTags[ i ].setAttribute( 'id', idTags[ i ].getAttribute( 'id' ) + '-' + this.validateId( this.id ) );
      }

      for( let i = 0; i < forTags.length; i++ ) {
        forTags[ i ].setAttribute( 'for', forTags[ i ].getAttribute( 'for' ) + '-' + this.validateId( this.id ) );
      }
    }

    for( let field in this.fields ) {
      if( typeof this.fields[ field ].roleIds !== 'object' || this.fields[ field ].roleIds === null ) continue;
      if( this.fields[ field ].roleIds.includes( currentRoleId ) ) continue;

      const fields = templateClone.querySelectorAll( '[name="' + field + '"]' );

      for( let i = 0; i < fields.length; i++ ) {
        fields[ i ].setAttribute( 'disabled', 'disabled' );
      }
    }

    targetContainer.append( templateClone  );

    return;
  }

/**
 * This method removes every template element from the page — it must
 * run after the blueprint on-ready callback (appendTemplate still
 * needs the template element).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.removeTemplates();
 *
 */
  removeTemplates() {
    const templates = document.querySelectorAll( 'template' );

    for( let i = 0; i < templates.length; i++ ) {
      templates[ i ].remove();
    }

    return;
  }

/**
 * This method fills a cloned form with the instance data: every
 * element with a data-property attribute receives its property value
 * — inputs and selects as value, everything else as innerHTML.
 * Missing properties are skipped. Expects the unsuffixed form id.
 *
 * @public
 *
 * @param     {string}   formId   The unsuffixed DOM id of the form
 * @return    {void}
 *
 * @example   instance.fillForm( 'user-form' );
 *
 */
  fillForm( formId ) {
    const form        = document.querySelector( '#' + formId + '-' + this.validateId( this.id ) );

    if( form === null ) return;

    const properties  = form.querySelectorAll( '[ data-property ]' );

    for( let i = 0; i < properties.length; i++ ) {
      const property        = properties[ i ].getAttribute( 'data-property' );
      const propertyTagName = properties[ i ].tagName.toLowerCase();

      if( ! this[ property ] ) continue;

      if( propertyTagName === 'input' ) {
        properties[ i ].value = this[ property ];
      } else if( propertyTagName === 'select' ) {
        properties[ i ].value = this[ property ];

        if( ! properties[ i ].hasAttribute( 'disabled' ) ) continue;

        const options = properties[ i ].querySelectorAll( 'option' );

        for( let i = 0; i < options.length; i++ ) {
          if( options[ i ].value === this[ property ] ) continue;

          options[ i ].remove();
        }
      } else {
        properties[ i ].innerHTML = this[ property ];
      }
    }

    return;
  }

/**
 * This method binds the instance owned fieldValidator onto the form and
 * starts the live validation: set( 'formId', ... ) binds the
 * fieldValidator unconditionally — every instance validates its own form,
 * parallel cards stay independent.
 *
 * @public
 *
 * @param     {string}    formId   The DOM id of the form
 * @param     {boolean}   setId    True to suffix the form id for cloned cards (default true)
 * @return    {void}
 *
 * @example   instance.validateForm( 'user-form', true );
 *
 */
  validateForm( formId, setId = true ) {
    formId = setId ? formId + '-' + this.validateId( this.id ) : formId;

    this.fieldValidator.set( 'fields', this.fields );
    this.fieldValidator.set( 'formId', formId );

    this.fieldValidator.validateFields();

    return;
  }

/**
 * This method fills the selects of a form with the options of the
 * fields property: fields.{name}.options (value → label) — the name
 * attribute of the select is the key.
 *
 * @public
 *
 * @param     {string}    formId   The DOM id of the form
 * @param     {boolean}   setId    True to suffix the form id for cloned cards (default true)
 * @return    {void}
 *
 * @example   instance.setSelectsOptions( 'user-form', true );
 *
 */
  setSelectsOptions( formId, setId = true ) {
    formId     = setId ? formId + '-' + this.validateId( this.id ) : formId;

    const form = document.querySelector( '#' + formId );

    if( form === null ) return;

    const selects = form.querySelectorAll( 'select' );

    for( let i = 0; i < selects.length; i++ ) {
      const selectName = selects[ i ].getAttribute( 'name' );

      if( typeof selectName !== 'string' || selectName === '' ) continue;
      if( typeof this.fields[ selectName ] !== 'object' || this.fields[ selectName ] === null ) continue;
      if( typeof this.fields[ selectName ].options !== 'object' || this.fields[ selectName ].options === null ) continue;

      for( let property in this.fields[ selectName ].options ) {
        const option     = document.createElement( 'option' );

        option.value     = property;
        option.innerHTML = this.fields[ selectName ].options[ property ];

        selects[ i ].append( option );
      }
    }

    return;
  }

/**
 * This method escapes an instance id into a DOM and CSS safe form:
 * '@' becomes _at_, '.' becomes _dot_, whitespace becomes '-' and
 * every remaining special character '_' — user ids are e-mail
 * addresses and the suffixes end in CSS selectors (#form-...).
 *
 * @public
 *
 * @param     {string}   id    The raw instance id
 * @return    {string}   id    The DOM safe id fragment
 *
 * @example   const suffix = instance.validateId( 'user@example.de' );
 *
 */
  validateId( id ) {
    id = id.replaceAll( '@', '_at_' );
    id = id.replaceAll( '.', '_dot_' );
    id = id.replace( /\s+/g, '-' );
    id = id.replace( /[^\w-]/g, '_' );

    return id;
  }

/**
 * This method builds the page persistent overlay: one '#overlay' root
 * element with outer/content containers and a close button (Escape
 * and click). The content arrives afterwards over
 * addContentToOverlay — the overlay is the display route of the JS
 * error channel.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.showOverlay();
 *
 */
  showOverlay() {
    if( document.querySelector( '#overlay' ) !== null ) return;

    const overlay                = document.createElement( 'div' );
    const overlayContent         = document.createElement( 'div' );
    const overlayOuterContent    = document.createElement( 'div' );
    const overlayButtons         = document.createElement( 'div' );
    const overlayCloseButton     = document.createElement( 'button' );

    overlay.id                   = 'overlay';
    overlayOuterContent.id       = 'overlay-outer-content';
    overlayContent.id            = 'overlay-content';
    overlayCloseButton.type      = 'button';
    overlayCloseButton.innerHTML = 'Schließen';
    overlay.tabIndex             = -1;

    overlay.classList.add( 'overlay' );
    overlayCloseButton.classList.add( 'primary' );
    overlayOuterContent.classList.add( 'overlay-outer-content' );

    overlayCloseButton.addEventListener( 'click', ( event ) => {
      this.closeOverlay();

      return;
    } );

    overlay.addEventListener( 'keydown', ( event ) => {
      if( event.key !== 'Escape' ) return;

      this.closeOverlay();

      return;
    } );

    overlayButtons.append( overlayCloseButton );
    overlayOuterContent.append( overlayContent );
    overlayOuterContent.append( overlayButtons );
    overlay.append( overlayOuterContent );

    document.querySelector( 'body' ).append( overlay );

    overlayCloseButton.focus();

    return;
  }

/**
 * This method removes the overlay when it exists.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   instance.closeOverlay();
 *
 */
  closeOverlay() {
    const overlay = document.querySelector( '#overlay' );

    if( overlay !== null ) overlay.remove();

    return;
  }

/**
 * This method fills the overlay content: objects (DOM nodes) get
 * appended, strings get concatenated as innerHTML. A missing overlay
 * is created on the fly.
 *
 * @public
 *
 * @param     {object|string}   content   The content as DOM node or HTML string
 * @return    {void}
 *
 * @example   instance.addContentToOverlay( '<p>Fehler</p>' );
 *
 */
  addContentToOverlay( content ) {
    if( document.querySelector( '#overlay' ) === null ) this.showOverlay();

    if( typeof content === 'object' ) {
      document.querySelector( '#overlay-content' ).append( content );
    } else {
      document.querySelector( '#overlay-content' ).innerHTML += content;
    }

    return;
  }

/**
 * This method adds the brand logo image to an html tag if configured
 * otherwise the app name is added. The first parameter is not passed
 * the method tries to find an html tag with the id: name-or-brand.
 * If a css class is specified as the second parameter, the brand logo image is sent
 * this css class attached.
 *
 * @public
 *
 * @param     {string|null}   tagId      Html tag id should be added in the place of the app name or the brand logo image
 * @param     {string|null}   cssClass   Css class which should be attached to the brand logo image
 * @return    {void}
 *
 * @example   instance.addBrandOrName( 'name-or-brand', 'user-login-container-brand-image' );
 *
 */
  addBrandOrName( tagId = null, cssClass = null ) {
    tagId = tagId && document.querySelector( '#' + tagId ) ? tagId : 'name-or-brand';

    if( document.querySelector( '#' + tagId ) === null ) return;

    const tag               = document.querySelector( '#' + tagId );
    const appBrandImagePath = this.config.appBrandImagePath;

    if( typeof appBrandImagePath === 'string' && appBrandImagePath != '' ) {
      const appBrandImage = document.createElement( 'img' );

      appBrandImage.src   = appBrandImagePath;
      appBrandImage.alt   = this.config.appName;
      appBrandImage.title = this.config.appName;

      if( cssClass ) appBrandImage.classList.add( cssClass )

      tag.append( appBrandImage );
    } else {
      tag.innerHTML  = this.config.appName;
    }

    return;
  }

}
