(function($){
	"use strict";
	$(document).ready(function(){
		/********************** Write jQuery/JavaScript Here **********************/

		var asampALB  = '.asamp-login-box';
		var asampALBA = '.asamp-login-box-activator';
		if($(asampALBA).length){
			$('body').on('click', asampALBA, function(e){
				$(asampALB).toggleClass('hidden');
					e.stopPropagation();
			});
			$('body').on('click', function(e){
				if(!$(asampALB).has(e.target).length > 0 && !$(asampALB).hasClass('hidden')){
					$(asampALB).toggleClass('hidden');
					e.stopPropagation();
				}
			});
		}
	
	});
})(jQuery);
