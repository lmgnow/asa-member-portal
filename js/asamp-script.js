(function($){
	"use strict";
	$(document).ready(function(){
		/********************** Write jQuery/JavaScript Here **********************/

		var asampALBA = '.asamp-login-box-activator';
		if($(asampALBA).length){
			$('body').on('click', asampALBA, function(e){
				$(asampALBA).toggleClass('hidden').next().toggleClass('hidden');
					e.stopPropagation();
			});
			$('body').on('click', function(e){
				if(!$('.asamp-login-box').has(e.target).length > 0 && $(asampALBA).hasClass('hidden')){
					$(asampALBA).toggleClass('hidden').next().toggleClass('hidden');
					e.stopPropagation();
				}
			});
		}
	
	});
})(jQuery);
