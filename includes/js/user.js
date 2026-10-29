/**
 * This class is the frontend instance of a user: it carries the
 * user data (without password hash — NOSERIALIZE on the PHP side)
 * and owns its form fieldValidator through the BaseObject. It builds
 * the top navbar of every view: app name, role-dependent menu
 * entries and the mobile burger toggle.
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.10
 * @since     2026-10-16
 *
 * @example   const user = window[ appAlias ].registry.get( 'user' );
 *
 */
class User extends BaseObject {

/**
 * This method is the constructor of the class: it wires only the
 * BaseObject base — the user data arrives as envelope (fillObject).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const user = new User();
 *
 */
  constructor() {
    super();

    return;
  }

/**
 * This method fills the navbar menu: Dashboard and Logout for every
 * user, the user administration entry only for administrators.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   user.addNavbarTopItems();
 *
 */
  addNavbarTopItems( app ) {
    const itemList = document.querySelector( '#navbar-top .navbar-menu-item-list' );
    const view     = app.currentView.id;

    this.addNavbarItem( 'Profil', 'index.php?view=viewUser', itemList );

    if( this.roleId === 'administrator' ) this.addNavbarItem( 'Benutzer', 'index.php?view=viewUserList', itemList );
    if( this.roleId === 'administrator' ) this.addNavbarItem( 'Diagnostics', 'index.php?view=viewDiagnostics', itemList );

    this.addNavbarItem( 'Logout', 'index.php?view=viewLogout', itemList );

    const itemListItems = document.querySelectorAll( '#navbar-top .navbar-menu-item-list .navbar-menu-item' );

    for( let i = 0; i < itemListItems.length; i++ ) {
      const link = itemListItems[ i ].querySelector( 'a' );

      if( typeof link !== 'object' || typeof link.href !== 'string' ) continue;
      if( view !== Utils.getUrlParamValue( link.href, 'view' ) ) continue;

      itemListItems[ i ].classList.add( 'navbar-menu-item-active' );
    }

    return;
  }

/**
 * This method builds the top navbar: app name, the mobile burger
 * toggle and the menu item list. The burger carries role="button"
 * and aria-expanded as screen-reader state, Enter and Space toggle
 * the menu like a real button. Runs only when the page carries
 * the '#navbar-top' container.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   user.addNavbarTop();
 *
 */
  addNavbarTop( app ) {
    if( document.querySelector( '#navbar-top' ) === null ) return;
    if( typeof app != 'object' ) return;

    const navbarTop             = document.querySelector( '#navbar-top' );
    const navbarTopAppName      = document.createElement( 'div' );
    const navbarTopToggleMobile = document.createElement( 'div' );
    const navbarTopMenuItemList = document.createElement( 'ul' );
    const navbarTopBurgerLine1  = document.createElement( 'span' );
    const navbarTopBurgerLine2  = document.createElement( 'span' );
    const navbarTopBurgerLine3  = document.createElement( 'span' );
    const appBrandImagePath     = this.config.appBrandImagePath;

    if( typeof appBrandImagePath === 'string' && appBrandImagePath != '' ) {
      const appBrandImage = document.createElement( 'img' );

      appBrandImage.src   = appBrandImagePath;
      appBrandImage.alt   = this.config.appName;
      appBrandImage.title = this.config.appName;

      appBrandImage.classList.add( 'navbar-top-brand-image' )

      navbarTopAppName.append( appBrandImage );
    } else {
      navbarTopAppName.innerHTML  = this.config.appName;
    }

    navbarTop.classList.remove( 'display-hidden' );
    navbarTopAppName.classList.add( 'navbar-top-name' );
    navbarTopToggleMobile.classList.add( 'navbar-top-toggle-mobile' );
    navbarTopBurgerLine1.classList.add( 'navbar-top-hamburger-bar' );
    navbarTopBurgerLine2.classList.add( 'navbar-top-hamburger-bar' );
    navbarTopBurgerLine3.classList.add( 'navbar-top-hamburger-bar' );
    navbarTopMenuItemList.classList.add( 'navbar-menu-item-list' );

    navbarTopToggleMobile.setAttribute( 'id', 'navbar-top-toggle-mobile' );
    navbarTopToggleMobile.setAttribute( 'role', 'button' );
    navbarTopToggleMobile.setAttribute( 'tabindex', '0' );
    navbarTopToggleMobile.setAttribute( 'aria-label', 'Menü' );
    // Screen readers announce role="button" as a button; whether the menu is open or closed reaches them only
    // over this state — the .active class is invisible for them. The value is carried along on every toggle.
    navbarTopToggleMobile.setAttribute( 'aria-expanded', 'false' );
    navbarTopMenuItemList.setAttribute( 'id', 'navbar-top-menu-item-list' );

    navbarTopToggleMobile.append( navbarTopBurgerLine1, navbarTopBurgerLine2, navbarTopBurgerLine3 );
    navbarTop.append( navbarTopAppName, navbarTopToggleMobile, navbarTopMenuItemList );

    const toggleMobileMenu = () => {
      navbarTopToggleMobile.classList.toggle( 'active' );
      navbarTopMenuItemList.classList.toggle( 'active' );

      navbarTopToggleMobile.setAttribute( 'aria-expanded', navbarTopMenuItemList.classList.contains( 'active' ) ? 'true' : 'false' );

      return;
    };

    navbarTopToggleMobile.addEventListener( 'click', () => {
      toggleMobileMenu();

      return;
    } );

    // A real button fires click automatically on Enter/Space — the role="button" div does not. The keydown
    // belongs on the burger (the only tabindex element), not on the ul: without tabindex it is never a keyboard
    // target. Space delivers a space character ' ' as event.key, not 'Space'. preventDefault keeps the page from scrolling.
    navbarTopToggleMobile.addEventListener( 'keydown', ( event ) => {
      if( event.key !== 'Enter' && event.key !== ' ' ) return;

      event.preventDefault();

      toggleMobileMenu();

      return;
    } );

    this.addNavbarTopItems( app );

    return;
  }

/**
 * This method appends a menu entry to a navbar list: as li with a
 * link, with optional click callback.
 *
 * @public
 *
 * @param     {string}    itemName   The visible name of the menu entry
 * @param     {string}    itemLink   The link target of the menu entry
 * @param     {object}    itemList   The list element (or its DOM id as string)
 * @param     {function}  funct      The optional click callback on the link
 * @return    {void}
 *
 * @example   user.addNavbarItem( 'Dashboard', 'index.php?view=viewUserList', itemList );
 *
 */
  addNavbarItem( itemName, itemLink, itemList, isActiv = false, funct = null ) {
    itemList = typeof itemList === 'object' ? itemList : document.querySelector( '#' + itemList );

    if( typeof itemList !== 'object' || itemList === null ) return;

    const item       = document.createElement('li');
    const link       = document.createElement('a');

    if( typeof funct === 'function' ) link.addEventListener( 'click', ( event ) => { funct( event ); } );

    link.href        = itemLink;
    link.textContent = itemName;

    item.classList.add( 'navbar-menu-item' );
    if( isActiv ) item.classList.add( 'navbar-menu-item-active' );

    item.append( link );
    itemList.append( item );

    return;
  }

}

window.User = User;
