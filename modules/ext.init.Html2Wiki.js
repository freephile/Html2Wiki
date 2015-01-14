/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


 
// init.js
$( function () {
    // This code must not be executed before the document is loaded. 
    Foo.sayHello( $( '#hello' ) );
});


// click the label, and show all the elments of the source
$( "#h2w-label" ).click(function() {
	// alert('hello world');

	var $content = $( "#h2w-content" ),
    str = $content.html(),
	html = $.parseHTML( str ),
	nodeNames = [];
	
	// Append the parsed HTML
	// $content.append( html );
	// Gather the parsed HTML's node names
	$.each( html, function( i, el ) {
	  nodeNames[ i ] = "<li>" + el.nodeName + "</li>";
	});

	// Insert the node names
	$content.append( "<h3>Node Names:</h3>" );
	$( "<ol></ol>" )
	  .append( nodeNames.join( "" ) )
	  .appendTo( $content );
	  
});