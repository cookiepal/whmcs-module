# Changelog

All notable changes to this module. It follows [Semantic Versioning](https://semver.org/). A version's
heading gets its release date when it is tagged.

## 1.0.0 (unreleased)

The first release.

- Create, suspend, unsuspend, change package and terminate CookiePal websites through the CookiePal agency API.
- One CookiePal customer per WHMCS client and CookiePal server: the client's later services on that server add websites
  to it. Terminate never removes a customer that another live service still uses.
- Running Create again on a service that already has its website succeeds without a call, and never creates a second
  website. Create sends an idempotency key, built from the WHMCS System URL and the service ID, so once the CookiePal
  API accepts it, running Create again after a lost answer completes the first request.
- Clear messages when the domain is already on CookiePal or is still being removed from it, when the client's email
  already has its own CookiePal account, and when another service already has the website.
- The client area shows the banner's install code, the plan, whether the banner is installed and any suspension, in the
  client's language when a translation exists.
- Single sign-on to the CookiePal dashboard ("Open CookiePal dashboard").
- The admin area shows the CookiePal ids, plan, status and script URL, with "Start scan" and "Refresh status" buttons.
- Every API call is written to the WHMCS Module Log, with the API key and sign-in links masked.
- The server's hostname must be a cookiepal.io host, so the API key can't be sent anywhere else.
- PHP 8.2 to 8.4, no dependencies, no ionCube.
