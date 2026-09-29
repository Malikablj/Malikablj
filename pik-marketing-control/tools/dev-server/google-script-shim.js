/*
 * Development stand-in for the client API HtmlService provides to a web app (google.script.run, google.script.history,
 * google.script.url). Injected by tools/dev-server only; in production Apps Script supplies the real objects.
 * google.script.run calls are sent to the dev server, which runs the real server function in the emulator.
 */
(function () {
  'use strict';

  function runner(onSuccess, onFailure, userObject) {
    var base = {
      withSuccessHandler: function (handler) { return runner(handler, onFailure, userObject); },
      withFailureHandler: function (handler) { return runner(onSuccess, handler, userObject); },
      withUserObject: function (object) { return runner(onSuccess, onFailure, object); }
    };
    return new Proxy(base, {
      get: function (target, name) {
        if (name in target) return target[name];
        if (typeof name !== 'string') return undefined;
        return function () {
          var args = Array.prototype.slice.call(arguments);
          fetch('/__rpc', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fn: name, args: args })
          }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
          }).then(function (body) {
            if (body.ok) {
              if (onSuccess) onSuccess(body.result, userObject);
            } else if (onFailure) {
              onFailure(new Error(body.error), userObject);
            }
          }).catch(function (error) {
            if (onFailure) onFailure(error, userObject);
          });
        };
      }
    });
  }

  function currentLocation() {
    var hash = window.location.hash.replace(/^#/, '');
    return { hash: hash, parameter: {}, parameters: {} };
  }

  var changeHandler = null;
  window.addEventListener('popstate', function (event) {
    if (changeHandler) changeHandler({ state: event.state, location: currentLocation() });
  });

  window.google = {
    script: {
      run: runner(null, null, undefined),
      history: {
        push: function (state, params, hash) { window.history.pushState(state || null, '', '#' + (hash || '')); },
        replace: function (state, params, hash) { window.history.replaceState(state || null, '', '#' + (hash || '')); },
        setChangeHandler: function (handler) { changeHandler = handler; }
      },
      url: {
        getLocation: function (callback) { setTimeout(function () { callback(currentLocation()); }, 0); }
      },
      host: { close: function () {}, setHeight: function () {}, setWidth: function () {}, editor: { focus: function () {} } }
    }
  };
})();
