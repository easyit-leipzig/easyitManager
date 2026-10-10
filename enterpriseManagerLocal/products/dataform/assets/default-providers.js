/**
 * easyIT DataForm – JavaScript-Standardwert-Provider
 *
 * Die Feldkonfiguration speichert ausschließlich den Namen eines Providers.
 * Ausführbarer JavaScript-Code bleibt in dieser versionierten Datei.
 *
 * Beispiel:
 * window.easyITDefaultProviders.customerNumber = function(context) {
 *     return 'KD-' + Date.now();
 * };
 */
(function(global){
    'use strict';

    var registry=global.easyITDefaultProviders=global.easyITDefaultProviders||{};

    registry.currentIso=function(context){
        var now=context&&context.now instanceof Date?context.now:new Date();
        return now.toISOString();
    };

    registry.today=function(context){
        var now=context&&context.now instanceof Date?context.now:new Date();
        var y=now.getFullYear();
        var m=String(now.getMonth()+1).padStart(2,'0');
        var d=String(now.getDate()).padStart(2,'0');
        return y+'-'+m+'-'+d;
    };

    registry.uuid=function(){
        if(global.crypto&&typeof global.crypto.randomUUID==='function'){
            return global.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){
            var r=Math.random()*16|0;
            var v=c==='x'?r:(r&0x3|0x8);
            return v.toString(16);
        });
    };

    registry.timestampMillis=function(){
        return String(Date.now());
    };
})(window);
