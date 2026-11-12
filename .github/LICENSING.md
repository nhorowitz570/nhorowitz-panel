# Licensing

ServerOS is open core. The panel in this repo is open source. Some paid features aren't in this repo at all.

## The panel: AGPL-3.0

Everything in this repo is licensed under the [GNU Affero General Public License v3.0](../LICENSE), unless a file says otherwise.

In plain words (the license text is what counts):

- You can run the panel for yourself, your team or your company, for free, on as many servers as you like.
- You can read, change and share the code.
- If you change the panel and let other people use your changed version over a network, for example as a hosted service, you have to offer them the source code of your changes under the AGPL too.
- Code you copy from this repo into another program brings the AGPL with it.

## Paid features: commercial license

Paid features live in a separate commercial package, `serveroshq/panel-ee`, in a private repo. It isn't open source and isn't covered by the AGPL.

- **The official image** (`ghcr.io/serveroshq/panel`) contains the panel from this repo plus panel-ee, under panel-ee's own license (its `LICENSE` file is in the image, in `vendor/serveroshq/panel-ee`). The paid features stay off until a workspace adds a valid ServerOS license key. Without a key, the official image works exactly like the community edition.
- **Building from this repo** gives you the community edition: AGPL-3.0 only, with no paid code at all. The open source panel works fully without panel-ee.

## ServerOS Cloud

ServerOS Cloud, the servers we run for you on [serveros.com](https://serveros.com), is a hosted service. Using it is covered by our [terms](https://serveros.com/terms), not by the AGPL.

## The daemon

The daemon that runs on your machines, [serverosd](https://github.com/serveroshq/daemon), has its own repo and is under the [AGPL-3.0](https://github.com/serveroshq/daemon/blob/main/LICENSE) too.

## Contributions

Contributions to this repo are made under our [Contributor License Agreement](CLA.md). It lets ServerOS Cloud Ltd ship your contribution in the open source panel and in our paid editions, while you keep the copyright in your work.

## A different license

If the AGPL doesn't work for your company, for example because you want to ship a modified panel without publishing your changes, email [liam@serveros.com](mailto:liam@serveros.com) about a commercial license.

## Trademarks

The AGPL covers the code, not the ServerOS name or logo. See [TRADEMARKS.md](TRADEMARKS.md).
