/**
 * This class represents the FieldValidator class with all properties and methods.
 * The FieldValidator class validates input fields as well as passwords and email addresses and throws form errors if necessary.
 * If a button with CSS class "submit", "save" or "new" is present in a form, it will be disabled or enabled.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.1.0
 * @since     2026-06-18
 *
 * @example   const fieldValidator = new FieldValidator();
 *
 */
class FieldValidator {

/**
 * This method is the constructor of the class.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const fieldValidator = new FieldValidator();
 *
 */
  constructor() {
    this.formErrors    = [];
    this.fields        = {};
    this.formId        = null;
    this.form          = null;
    this.fieldTypes    = [ 'input', 'select', 'textarea' ];
    this.passwordRules = window[ appAlias ].config.passwordRules;
    this.debug         = window[ appAlias ].config.debug ? true : false;

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
 * @example   const value = fieldValidator.get( property );
 *
 * @see FieldValidator#set
 *
 */
  get( property ) {
    return this[ property ];
  }

/**
 * This method is the default setter for the class.
 *
 * @public
 *
 * @param     {string}   property   The property that you want to set
 * @param     {*}        value      The value you want to set to the property
 * @return    {void}
 *
 * @example   fieldValidator.set( property, value );
 *
 * @see FieldValidator#get
 *
 */
  set( property, value ) {
    if( property === 'formId' ) this.form = document.querySelector( '#' + value );

    this[ property ] = value;

    return;
  }

/**
 * This method goes through all the input fields of a form and adds three to each input field
 * added event handler. One when changing the input field, one when leaving the input field and one
 * when pressing a key in the input field.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   fieldValidator.validateFields();
 *
 */
  validateFields() {
    if( typeof this.form !== 'object' || this.form === null ) return;

    this._validateFields();

    for( let i = 0; i < this.fieldTypes.length; i++ ) {
      const fieldObjects = this.form.querySelectorAll( this.fieldTypes[ i ] );

      if( fieldObjects == null ) continue;

      for( let j = 0; j < fieldObjects.length; j++ ) {
        if( fieldObjects[ j ] == null ) continue;
        if( fieldObjects[ j ].classList.contains( 'no-validate' ) ) continue;

        fieldObjects[ j ].addEventListener( 'change', ( event ) => { this._validateFields(); }, false );
        fieldObjects[ j ].addEventListener( 'keyup', ( event ) => { this._validateFields(); }, false );
        fieldObjects[ j ].addEventListener( 'blur', ( event ) => { this._validateFieldSetFormError( event ); }, false );
      }
    }

    return;
  }

/**
 * This method sets or removes a FormError from the input fields of a form when typing with the keyboard or changing the field.
 *
 * @private
 *
 * @param     {object}  event   The triggering event
 * @return    {void}
 *
 * @example   fieldValidator._validateFieldSetFormError( event );
 *
 */
  _validateFieldSetFormError( event ) {
    const form = event.target.closest( 'form' );

    if( this._validateField( event.target.name, form ) ) {
      event.target.classList.remove( 'form-error' );
    } else {
      event.target.classList.add( 'form-error' );
    }

    return;
  }

/**
 * This method checks the field passed with the input field name whether it meets all the guidelines
 * the input field is stored (fields.json).
 *
 * @private
 *
 * @param     {string}   key            The name of the input field
 * @param     {object}   form           The form in which the key is located
 * @return    {boolean}  formIsValid   The result of the check, true: is valid, false: is not valid
 *
 * @example   const formIsValid = fieldValidator._validateField( key, form );
 *
 */
  _validateField( key, form ) {
    const fields     = this.fields;
    let formIsValid  = true;

    if( typeof key !== 'string' || key === '' ) return true;
    if( typeof fields !== 'object' || fields == null ) return true;
    if( typeof fields[ key ] === 'undefined' || fields[ key ] == null ) return true;

    if( ! fields[ key ].mandatory ) return true;
    const field = form.querySelector( fields[ key ].element + '[name=\'' + key + '\']' );

    if( typeof field !== 'object' || field == null ) return true;
    if( field.classList.contains( 'no-validate' ) ) return true;

    if( fields[ key ].type === 'checkbox' ) {
      if( ! field.checked ) formIsValid = false;
    } else if( fields[ key ].type === 'password' ) {
      const minLength = typeof fields[ key ].minLength === 'number' ? fields[ key ].minLength : 1;
      if( typeof field.value !== 'string' || field.value === '' ) formIsValid = false;
      if( field.value.length < minLength ) formIsValid = false;
      const password2 = form.querySelector( fields[ key ].element + '[name=\'' + key + '2\']' );
      if( password2 != null && typeof password2.value === 'string' && field.value !== password2.value ) formIsValid = false;
      if( typeof fields[ key ].validatePasswordSecurity === 'boolean' && fields[ key ].validatePasswordSecurity ) {
        if( ! this._validatePassword( field.value ) ) formIsValid = false;
      }
    } else {
      const minLength = typeof fields[ key ].minLength === 'number' ? fields[ key ].minLength : 1;
      if( typeof field.value !== 'string' || field.value === '' ) formIsValid = false;
      if( field.value.length < minLength ) formIsValid = false;
    }
    if( ! formIsValid ) return false;
    if( fields[ key ].mail && ! this._validateEmail( field.value ) ) formIsValid = false;
    if( formIsValid ) field.classList.remove( 'form-error' );

    return formIsValid;
  }

/**
 * This method loops through all fields of a form and validates the input.
 * In addition, the buttons are toggled to disable or enable using the CSS class: "submit", "save" and "new".
 *
 * @private
 *
 * @return    {void}
 *
 * @example   fieldValidator._validateFields();
 *
 */
  _validateFields() {
    const fields    = this.fields;
    let formIsValid = true;

    if( typeof fields !== 'object' || fields == null ) return;

    for( const key in fields ) {
      formIsValid = this._validateField( key, this.form );

      if( ! formIsValid ) break;
    }

    if( formIsValid ) {
      if( this.form.querySelector( '.submit' ) !== null ) this.form.querySelector( '.submit' ).removeAttribute( 'disabled' );
      if( this.form.querySelector( '.save' ) !== null ) this.form.querySelector( '.save' ).removeAttribute( 'disabled' );
      if( this.form.querySelector( '.new' ) !== null ) this.form.querySelector( '.new' ).removeAttribute( 'disabled' );
    } else {
      if( this.form.querySelector( '.submit' ) !== null ) this.form.querySelector( '.submit' ).setAttribute( 'disabled', 'disabled' );
      if( this.form.querySelector( '.save' ) !== null ) this.form.querySelector( '.save' ).setAttribute( 'disabled', 'disabled' );
      if( this.form.querySelector( '.new' ) !== null ) this.form.querySelector( '.new' ).setAttribute( 'disabled', 'disabled' );
    }

    return;
  }

/**
 * This method is specifically for passwords and checks the password against the stored rules.
 *
 * @private
 *
 * @param     {string}   password   The password
 * @return    {boolean}  isValide   The result of the check, true: is valid, false: is not valid
 *
 * @example   const isValide = fieldValidator._validatePassword( password );
 *
 */
  _validatePassword( password ) {
    if( this.passwordRules.passwordHasNumbers ) {
      const regexNumbers = /[0-9]/;
      if( ! regexNumbers.test( password ) ) return false;
    }

    if( this.passwordRules.passwordHasCapitalLetters ) {
      const regexCapitalLetters = /[A-Z]/;
      if( ! regexCapitalLetters.test( password ) ) return false;
    }

    if( this.passwordRules.passwordHasLowercaseLetters ) {
      const regexLowercaseLetters = /[a-z]/;
      if( ! regexLowercaseLetters.test( password ) ) return false;
    }

    if( this.passwordRules.passwordHasSpecialCharacters ) {
      const regexSpecialCharacters = /[^a-zA-Z0-9\s]/;
      if( ! regexSpecialCharacters.test( password ) ) return false;
    }

    return true;
  }

/**
 * This method checks an email address for validity and returns the result of the check.
 *
 * @private
 *
 * @param     {string}   email      The email address
 * @return    {boolean}  isValide   The result of the check, true: is valid, false: is not valid
 *
 * @example   const isValide = fieldValidator._validateEmail( email );
 *
 */
  _validateEmail( email ) {
    return String( email ).toLowerCase().match( /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|.(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/ );
  }

};
