# Views display switch
This module provides a Views area plugin that can be placed in the header or the footer of a view and can generate Links to configurable displays of that view.

## Features
* Link labels can be customized
* Exposed filter and pager parameters are maintained in the URL
* Pages and Blocks are available for the switch
* Using blocks the switch can also be used on views without a page display. This makes it very flexible and compatible with vies blocks placed on the page or rendered using for example Paragraphs.


## Limitations
* Currently is only working if there is one view on the page (This hasn't been tested and the results might be unwanted or throw errors).
* While the functionality is working, it is recommended to either use block or page displays. Mixing both display types can lead to weird situations.

## Installtation
* Install module as usual
* Add the "Display switch" handler to either the header or the footer of a view
* Configure which displays to enable for the switch and set a label for each
