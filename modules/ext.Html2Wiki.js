/**
 * JavaScript for Html2Wiki.
 */
/** More modern example, but without any defintion
( function ( mw, $ ) {
	var foo, x, y;

	function my() {

	}

	foo = {
		init: function () {
			// ..
		},
		doStuff: function () {
			// ..
		}
	};

	mw.libs.foo = foo;

}( mediaWiki, jQuery ) );
*/
/** 
 * 
 * practical example to get working
 */

// Execute this after the site is loaded.
jQuery(document).ready(function ($) {
    // Find list items representing folders and
    // style them accordingly.  Also, turn them
    // into links that can expand/collapse the
    // tree leaf.
    $('li > ul').each(function (i) {
        // Find this list's parent list item.
        var parent_li = $(this).parent('li');
 
        // Style the list item as folder.
        parent_li.addClass('folder');
 
        // Temporarily remove the list from the
        // parent list item, wrap the remaining
        // text in an anchor, then reattach it.
        var sub_ul = $(this).remove();
        parent_li.wrapInner('<a>').find('a').click(function () {
            // Make the anchor toggle the leaf display.
            sub_ul.toggle();
        });
        parent_li.append(sub_ul);
    });
 
    // Hide all lists except the outermost.
    $('ul ul').hide();
});