/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


// foo.js
var Foo = {
    sayHello: function ( $element ) {
        $element.append( '<p>Hello Module!</p>' );
    }
};
window.Foo = Foo;


