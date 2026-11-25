# CookiePal for WHMCS

Sell the [CookiePal](https://cookiepal.io/) cookie consent banner from WHMCS. When a customer orders, WHMCS creates their CookiePal website through the CookiePal agency API. When they pay late, upgrade, downgrade or cancel, WHMCS suspends, changes or removes it. The customer gets the banner's install code in the WHMCS client area, and opens their CookiePal dashboard with one click.

Your WHMCS customers become customers of your CookiePal agency account. They show in your agency dashboard, and CookiePal bills you for them through your agency account. You bill your customers in WHMCS, at your own prices.

**Requirements:** WHMCS running on PHP 8.2, 8.3 or 8.4 with the curl extension, and a CookiePal agency account. The PHP intl extension is needed only for international domain names (such as `münchen.de`). The module has no dependencies and isn't encoded with ionCube.

**WHMCS versions tested:** to be added before the 1.0.0 release.

## 1. Ask CookiePal for an agency API key

Email [support@cookiepal.io](mailto:support@cookiepal.io) from your agency account's email and ask for a WHMCS API key. You get a key that starts with `cpk_`. CookiePal shows it once, so store it somewhere safe.

The key acts for your whole agency account. If it leaks, ask CookiePal to revoke it and issue a new one: the old key stops working within a minute.

## 2. Upload the module

Download `cookiepal-whmcs-<version>.zip` from the [releases](https://github.com/cookiepal/whmcs-module/releases) and unzip it into your WHMCS root folder. It holds one folder only:

```
modules/servers/cookiepal/
```

To update, unzip the new version over the old one. The module creates its own table, `mod_cookiepal_services`, the first time it runs. If you remove the module, the table stays; drop it yourself once you no longer need to know which CookiePal website each service was.

## 3. Add a server and a server group

1. In WHMCS, go to **Configuration > System Settings > Servers > Add New Server**:

   | Field | Value |
   |---|---|
   | Module | CookiePal |
   | Name | CookiePal (any name you like) |
   | Hostname | `agency-api.cookiepal.io` |
   | Password | your API key (`cpk_…`) |

   Leave the username, IP address and access hash empty. Click **Test Connection**: it should succeed. The module always connects over HTTPS, and only to `cookiepal.io` hosts; an empty hostname means `agency-api.cookiepal.io`.
2. Back on the **Servers** page, click **Create New Group**, name it (for example "CookiePal"), add the CookiePal server to it and save.

## 4. Create a product

1. Go to **Configuration > System Settings > Products/Services > Create a New Product**. Choose the type **Other** and give it a name, for example "Cookie banner – Growth".
2. On the **Details** tab, tick **Require Domain**. The module needs the customer's website domain.
3. On the **Module Settings** tab, choose the **CookiePal** module and the server group from step 3, then pick the **Plan**: Free, Starter, Growth or Business. Choose "Automatically setup the product as soon as the first payment is received" if you want orders provisioned without you.
4. Set your prices on the **Pricing** tab.

Create one product per plan. To let customers upgrade or downgrade between them, select the other CookiePal products on each product's **Upgrades** tab.

To sell one product with a choice of plans instead, add a configurable option named **Plan** to the product, with the option values `free`, `starter`, `growth` and `business` (WHMCS's `value|Display name` format works, for example `growth|Growth`). When a service has that option, it wins over the product's Plan setting.

## 5. What each WHMCS action does

| WHMCS action | What happens in CookiePal |
|---|---|
| **Create** | The client's first CookiePal service creates a CookiePal customer (named after the client's company, or their first and last name) with the service's domain on the product's plan. CookiePal emails the client an invitation to their dashboard. The client's next services on the same CookiePal server add their domains to the same CookiePal customer. Running Create again on a service that already has its website does nothing and succeeds, and running it again after an answer was lost can complete the first request (see Troubleshooting). A second service for a website another service already has is refused. |
| **Suspend** | Switches the banner off on the website. Nothing else changes, so Unsuspend brings it back exactly as it was. |
| **Unsuspend** | Switches the banner back on. |
| **Upgrade / Downgrade** (Change Package) | Changes the website's plan to the new product's plan. |
| **Terminate** | Removes the website. When it is the CookiePal customer's last active website and no other live service uses that customer, the whole customer is removed. CookiePal deletes what was removed a day later. |
| **Start scan** (admin button) | Starts a cookie scan of the website. The banner must be installed and verified first. |
| **Refresh status** (admin button) | Reads the website from CookiePal again. |

The service's page in the admin area shows the CookiePal customer ID, the website, the plan, whether the banner is installed, the script URL, the suspension and the status. Times are in UTC.

The domain is taken from the service's Domain field when Create runs: the protocol, path and a leading `www.` are removed, and it is lowercased. `https://www.Example.com/shop` becomes `example.com`. Editing the Domain field later has no effect; the service stays on the website it was created with.

### The client area

On the service's page, the client sees:

- the banner's install code, with a Copy button
- the plan, and whether the banner is installed on their website
- a notice while the service is suspended or the website is being removed
- the **Open CookiePal dashboard** button

The page waits at most 5 seconds for CookiePal; if CookiePal doesn't answer in time, the client sees a short "try again later" notice instead.

The text comes from `modules/servers/cookiepal/lang/english.php`. To translate it, copy that file to `lang/<language>.php` (the WHMCS language name, for example `french.php`) and change the values. Any string you leave out stays in English, and if the file has an error the client sees English. The module uses the client's language, else the language of the current session, else WHMCS's default language.

## 6. Single sign-on

**Open CookiePal dashboard** signs the client in to their CookiePal dashboard without a password. WHMCS asks CookiePal for a sign-in link that works once and expires after 60 seconds, then sends the client to it. The client is signed in as their own CookiePal user: the email of the WHMCS client who placed the first order. The link is masked in the Module Log.

The same button appears on the service's page in the admin area, and signs you in as that client.

If the client's CookiePal user can't use single sign-on (for example, because their account needs two-factor authentication), they see: "Sign in at app.cookiepal.io with the email the invite went to." For any other failure the client sees a short "try again in a few minutes, or contact us" message; the admin area and the Module Log show the reason.

## 7. Troubleshooting

Open **Configuration > System Logs > Module Log** and turn on debug logging, repeat the action, then read the entries for `cookiepal`. Each entry is one API call, with the request, CookiePal's answer and its HTTP status. Your API key and sign-in links are masked. Turn the logging off again when you're done.

| Message | What to do |
|---|---|
| CookiePal refused the API key (403) | The key in the server's Password field is wrong or was revoked. Check it, or ask CookiePal for a new one. |
| CookiePal received no API key (401) / No CookiePal API key | Enter the key in the server's Password field. |
| This CookiePal API host doesn't have this feature yet (403) | The hostname points at a CookiePal API that doesn't have this action yet. Check the hostname is `agency-api.cookiepal.io`, or try again once CookiePal has released it. |
| The server's hostname isn't a CookiePal API host | Set the server's Hostname to `agency-api.cookiepal.io`. |
| Couldn't reach CookiePal | Check that your WHMCS server can make outgoing HTTPS requests to the hostname, and that the hostname is right. |
| Enter the customer's website domain on the service first. / The service's Domain field isn't a website domain | Fill in or correct the service's Domain field, then run Create again. |
| The domain … is already registered to another CookiePal account. | The website is already on CookiePal under another account, which can be another customer of your own agency. The customer can remove it there, or ask CookiePal support to move it to your agency. |
| The email … already has its own CookiePal account. | The client already uses CookiePal on their own. Ask CookiePal support to link that account to your agency, then run Create again. |
| This client's CookiePal service #… was terminated on …, and CookiePal is still removing it / The website … is being removed from CookiePal | The client's earlier service for this website or account was terminated, and CookiePal deletes it a day later. Run Create again the next day, or contact CookiePal support. |
| The email … belongs to the CookiePal customer of this client's service #…, which CookiePal kept | That service was terminated recently, but its CookiePal customer stays because it has other websites, so its email can't start a new customer. Contact CookiePal support with both service IDs. |
| The email … already has a CookiePal account through this client's service #…, which is on another CookiePal server | A client's CookiePal services share one CookiePal customer, which belongs to the agency key of the server the first one was created on. Put the client's CookiePal products on the same server, or contact CookiePal support. |
| This website already belongs to service #… | Another service already has this website. Terminate that service first, or cancel the new order. |
| CookiePal won't remove … because it's the customer's only active website, and service #… still uses that customer | The other service's website isn't active in CookiePal, so CookiePal keeps this one. Terminate the other service first, then run Terminate again on this one; or contact CookiePal support. |
| The customer pays for this website's plan in CookiePal themselves | The client upgraded inside CookiePal, so the plan is no longer yours to change. |
| This website has more traffic than the free plan allows. | Choose a paid plan. |
| Another plan change for this website was in progress. | Run the action again in a minute. |
| This CookiePal website is scheduled for deletion | The website is being removed. Create a new service if the client wants it back. |
| CookiePal module error: … | Something failed inside WHMCS or the module. The Module Log has the details; send them to [support@cookiepal.io](mailto:support@cookiepal.io). |
| CookiePal API error (…) | Read the Module Log entry, and contact [support@cookiepal.io](mailto:support@cookiepal.io) with the time and the service ID. |

If Create fails with "Couldn't reach CookiePal", or with a CookiePal API error 502 or 504, CookiePal may still have created the website. Run Create again. Every Create sends a request key made from the service ID and your WHMCS System URL: when the CookiePal API recognises the key, it answers the retry with the website the first request created, and creates nothing new. The key isn't secret, and you'll see it in the Module Log. Keep the System URL unchanged until the retry: with a new System URL, the retry counts as a new request.

If the retry answers that the email already has its own CookiePal account, or that the domain is registered to another account, CookiePal didn't match it to the first request: the first request may have created them, or they may belong to someone else. CookiePal support can tell which, and finish the setup. Contact [support@cookiepal.io](mailto:support@cookiepal.io) with the service ID and the domain.

Every WHMCS error message is also in the WHMCS Activity Log.

## Development

```sh
composer install
vendor/bin/phpunit
scripts/build-zip.sh        # dist/cookiepal-whmcs-<version>.zip, from the last commit
```

Or, without PHP installed:

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit
```

The tests replace WHMCS's `logModuleCall`, `logActivity`, `Capsule` and settings with stubs (`tests/Stubs/`) and the HTTP transport with a fake (`tests/Support/FakeTransport.php`), so they need neither WHMCS nor the network. The client area template is rendered with Smarty.

To release, set `Module::VERSION` and date the version's heading in `CHANGELOG.md`, then push a `v<version>` tag. The release workflow runs the CI tests on the tagged commit, checks that the three agree, builds the zip and attaches it to the GitHub release.

## Security

See [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE)
