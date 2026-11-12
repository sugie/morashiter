// Service worker: listens for tab changes and asks the monitor to check each tab.
// It keeps nothing important in memory; results live in chrome.storage.session, so a stopped
// and restarted worker picks them up again.

import { createMonitor } from "./monitor.js";
import { createDataSource } from "./data-source.js";
import { createRemoteDataSource } from "./remote-data-source.js";
import { demoScenarios } from "./demo-data.js";

const dataSource = createDataSource({
  remote: createRemoteDataSource({ storage: chrome.storage.local }),
});
const monitor = createMonitor({ api: chrome, dataSource, scenarios: demoScenarios });

function report(error) {
  console.error("[Morashiter]", error);
}

function checkTab(tab, options) {
  if (tab && tab.id !== undefined && tab.id !== chrome.tabs.TAB_ID_NONE) {
    return monitor.handleUrl(tab.id, tab.url ?? tab.pendingUrl, options).catch(report);
  }
  return Promise.resolve();
}

async function checkAllTabs() {
  const tabs = await chrome.tabs.query({});
  await Promise.all(tabs.map((tab) => checkTab(tab)));
}

// URL changes (including in-page navigation) and finished loads.
chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.url || changeInfo.status === "complete") {
    checkTab(tab);
  }
});

// Switching tabs: make sure the newly shown tab has a current result and badge.
chrome.tabs.onActivated.addListener(({ tabId }) => {
  chrome.tabs.get(tabId).then((tab) => checkTab(tab), report);
});

// A tab can be swapped for a prerendered one with a new id.
chrome.tabs.onReplaced.addListener((addedTabId, removedTabId) => {
  monitor.forgetTab(removedTabId).catch(report);
  chrome.tabs.get(addedTabId).then((tab) => checkTab(tab), report);
});

chrome.tabs.onRemoved.addListener((tabId) => {
  monitor.forgetTab(tabId).catch(report);
});

chrome.runtime.onInstalled.addListener(() => checkAllTabs().catch(report));
chrome.runtime.onStartup.addListener(() => checkAllTabs().catch(report));

// Requests from the popup. The popup tells us which tab it is showing.
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  (async () => {
    const tab = await chrome.tabs.get(message.tabId);
    const url = tab.url ?? tab.pendingUrl;
    switch (message.type) {
      case "get":
        return monitor.handleUrl(tab.id, url);
      case "recheck":
        return monitor.handleUrl(tab.id, url, { force: true });
      case "setDemo":
        return monitor.setDemo(tab.id, message.scenarioId, url);
      case "clearDemo":
        return monitor.clearDemo(tab.id, url);
      default:
        throw new Error(`unknown message: ${message.type}`);
    }
  })().then(
    (result) => sendResponse({ ok: true, result }),
    (error) => {
      report(error);
      sendResponse({ ok: false, error: String(error?.message ?? error) });
    },
  );
  return true; // answer asynchronously
});

// Each time the worker starts (first install, browser start, or waking after being stopped),
// bring every tab's result and badge up to date. Stored results are reused, not looked up again.
checkAllTabs().catch(report);
