# Adapter scope

The shared core provides `Gateway`, `OrderStore`, `Settler` and `ArrayOrderStore`.
HikaShop and VirtueMart have separate payment and Joomla scheduler plugins.
The packaging repository builds one installable package per cart, containing its
payment plugin and task plugin.

The settlement worker uses stored XMR amounts, accumulated matches and checkpoints.
Each cart adapter owns its transaction claim and fulfillment semantics. Scheduler
execution uses Joomla Web Cron because the commerce extensions expect a web
application context.

J2Store/J2Commerce remains unimplemented in this suite. Adding it requires validating
the target platform's payment and order APIs and testing a complete stagenet checkout.
No delivery date or compatibility claim is implied.
