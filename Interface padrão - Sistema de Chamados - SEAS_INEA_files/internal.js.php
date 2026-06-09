      $(function () {      $('link[href*="trademark/front/internal.css.php"]').appendTo($('head'))
               var $icon = $('link[rel*=icon]');
         $icon.attr('type', null);
         $icon.attr('href', "\/plugins\/trademark\/front\/picture.send.php?path=e0\/68b19c7e303e0.jpg");
                  var $title = $('title');
         var newTitle = $title.text().replace('GLPI', "Sistema de Chamados - SEAS\/INEA");
         $title.text(newTitle);
                  $('div[id^=about_modal_] .copyright').parent().parent().html("<h3 style=\"text-align: center;\"><span style=\"color: #ffffff;\">-<\/span><\/h3>\n<h3 style=\"text-align: center;\"><span style=\"color: #363286;\">Para iniciar digite seu login e senha usados\u00a0<br \/><\/span><span style=\"color: #363286;\">para conectar ao computador<\/span><\/h3>");
         });