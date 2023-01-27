
 async function renderReadme() {
    const resp = await fetch(readmefile)
    if(!resp.ok) return
    document.querySelector(".container").insertAdjacentHTML('beforeend','<div class="readme">'+window.marked(await resp.text()) + "</div>")
    Prism.highlightAll()
 }


function humanFileSize(bytes, si) {
    bytes = parseInt(bytes,10)
    var thresh = si ? 1000 : 1024;
    if(Math.abs(bytes) < thresh) {
        return bytes + ' B';
    }
    var units = si
        ? ['kB','MB','GB','TB','PB','EB','ZB','YB']
        : ['KiB','MiB','GiB','TiB','PiB','EiB','ZiB','YiB'];
    var u = -1;
    do {
        bytes /= thresh;
        ++u;
    } while(Math.abs(bytes) >= thresh && u < units.length - 1);
    return bytes.toFixed(1)+' '+units[u];
}

document.addEventListener("DOMContentLoaded", function() {

    document.querySelectorAll("a.item").forEach(function(i){
        i.insertAdjacentHTML('beforeend',
            '<div style="flex-grow:1"></div>'
            + ( i.hasAttribute("size") ? '<span class="size">'+humanFileSize(i.getAttribute("size"),true)+'</span>' : '')
        )
    })

 });

import "https://fastly.jsdelivr.net/npm/marked/marked.min.js"

renderReadme().catch(console.error)
