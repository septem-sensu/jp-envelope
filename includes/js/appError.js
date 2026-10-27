/**
 * This class is a frontend error card: message, severity ('error' |
 * 'warning') and source travel as properties, the display runs as
 * DOM card over the overlay of the BaseObject. The PHP channel
 * delivers the envelopes (ids error{N}), the JS channel registers
 * its own instances (ids errorjs{N}). Deliberately not named Error:
 * Error is a built-in in PHP (Throwable base) as well as in
 * JavaScript (window.Error).
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-20
 *
 * @example   const appErrorInstance = new AppError();
 *
 */
class AppError extends BaseObject {

/**
 * This method is the constructor of the class: it wires only the
 * BaseObject base (fieldValidator, loader/config references, global
 * event handlers).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const appErrorInstance = new AppError();
 *
 */
  constructor() {
    super();

    return;
  }

/**
 * This method builds the error card (headline, message, id and
 * source meta lines) and shows it in the overlay — the severity
 * becomes part of the css class (app-error-severity-*).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   appErrorInstance.showError();
 *
 */
  showError() {
    // idempotent: JS runtime errors before app-ready are shown immediately — the late sweep in showAppErrors() must not double.
    if( document.getElementById( this.id ) !== null ) return;

    const errorContent             = document.createElement( 'div' );
    const errorHeadline            = document.createElement( 'div' );
    const errorBody                = document.createElement( 'div' );
    const errorBodyMeta1           = document.createElement( 'div' );
    const errorBodyMeta2           = document.createElement( 'div' );
    const errorHeadlineIcon        = document.createElement( 'span' );
    const errorHeadlineContent     = document.createElement( 'span' );
    const errorBodyMeta1Label      = document.createElement( 'span' );
    const errorBodyMeta1Value      = document.createElement( 'span' );
    const errorBodyMeta2Label      = document.createElement( 'span' );
    const errorBodyMeta2Value      = document.createElement( 'span' );
    const errorMessage             = document.createElement( 'p' );
    const errorSource              = typeof this.source != 'string' || this.source === '' ? 'default' : this.source;
    const cssErrorClass            = 'app-error-severity-' + this.severity;

    errorContent.id                = this.id;
    errorHeadlineContent.innerHTML = Utils.firstLetterToUpperCase( this.severity );
    errorMessage.innerHTML         = this.message;
    errorBodyMeta1Label.innerHTML  = 'Error ID:';
    errorBodyMeta1Value.innerHTML  = Utils.firstLetterToUpperCase( this.id );
    errorBodyMeta2Label.innerHTML  = 'Source:';
    errorBodyMeta2Value.innerHTML  = errorSource;

    errorContent.classList.add( 'app-error', cssErrorClass );
    errorHeadline.classList.add( 'app-error-headline', cssErrorClass );
    errorHeadlineIcon.classList.add( 'app-error-headline-icon', cssErrorClass );
    errorHeadlineContent.classList.add( 'app-error-headline-content', cssErrorClass );
    errorMessage.classList.add( 'app-error-body-content', cssErrorClass );
    errorBody.classList.add( 'app-error-body', cssErrorClass );
    errorBodyMeta1.classList.add( 'app-error-body-id', cssErrorClass );
    errorBodyMeta1Label.classList.add( 'app-error-body-id-label', cssErrorClass );
    errorBodyMeta1Value.classList.add( 'app-error-body-id-value', cssErrorClass );
    errorBodyMeta2.classList.add( 'app-error-body-source', cssErrorClass );
    errorBodyMeta2Label.classList.add( 'app-error-body-source-label', cssErrorClass );
    errorBodyMeta2Value.classList.add( 'app-error-body-source-value', cssErrorClass );

    errorHeadline.append( errorHeadlineIcon, errorHeadlineContent );
    errorBodyMeta1.append( errorBodyMeta1Label, errorBodyMeta1Value );
    errorBodyMeta2.append( errorBodyMeta2Label, errorBodyMeta2Value );
    errorBody.append( errorBodyMeta1, errorBodyMeta2 );
    errorContent.append( errorHeadline, errorMessage, errorBody );

    this.addContentToOverlay( errorContent );

    return;
  }
}

window.AppError = AppError;