/**
 * Web app entry points.
 */

function doGet() {
  return HtmlService.createTemplateFromFile('web/Index')
    .evaluate()
    .setTitle(getConfig_().APP_NAME)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

/** Template include for HtmlService scriptlets. Private (trailing underscore): not callable from the client. */
function include_(name) {
  return HtmlService.createHtmlOutputFromFile(name).getContent();
}

function getAppHealth() {
  return handleRequest_(function () {
    return { app: getConfig_().APP_NAME, schemaVersion: SCHEMA_VERSION, timestamp: nowIso_() };
  });
}
