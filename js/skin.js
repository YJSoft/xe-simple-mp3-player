var xhr = new XMLHttpRequest;
var url = window.request_uri+'addons/simple_mp3_player/getskinlist.php?act=getSimpleMP3SkinList';
xhr.open('GET', url, true);
xhr.send();
xhr.addEventListener('load', function(){
    var data = xhr.response;
    if (xhr.status != 200) {
        reject(xhr.status);
    } else {
        try {
            var result = JSON.parse(data);
            console.log(result);
        } catch(e){
            console.error(e);
        }
    }
}, false);
