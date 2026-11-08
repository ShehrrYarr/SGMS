@php
    /** SGMS product page. Styles: public/landing/landing.css. Screenshots: public/landing/shots. */
    $shot = fn (string $name) => asset('landing/shots/'.$name.'.webp');

    $portals = [
        'owner' => ['role' => 'Owner', 'who' => 'Ahmed runs Urban Garments', 'sees' => 'Everything: purchases, stock, reports, settings and the online store.', 'button' => 'Open as owner'],
        'manager' => ['role' => 'Manager', 'who' => 'Sara manages the City Mall branch', 'sees' => 'Her branch: stock, sales, transfers and online orders.', 'button' => 'Open as manager'],
        'cashier' => ['role' => 'Cashier', 'who' => 'Hamza works the counter', 'sees' => 'Goes straight to the POS, with his own sales and returns.', 'button' => 'Open as cashier'],
        'customer' => ['role' => 'Customer', 'who' => 'Bilal shops there often', 'sees' => 'His online orders, past purchases and balance with the shop.', 'button' => 'Open as customer'],
    ];

    $tour = [
        ['id' => 'buy', 'title' => 'Buy a lot', 'shot' => 'purchase', 'alt' => 'A lot purchase in SGMS with quantities for each colour and size',
         'text' => 'Enter a lot the way it arrives: the article, its colours, and how many pieces of each size. Pay the vendor now, later, or part of both, and the vendor ledger keeps up.'],
        ['id' => 'stock', 'title' => 'Stock every branch', 'shot' => 'inventory', 'alt' => 'Stock on hand per branch in SGMS',
         'text' => 'See what is on the shelves in each branch down to colour and size, move pieces between branches, and print barcode labels for every size.'],
        ['id' => 'sell', 'title' => 'Sell at the counter', 'shot' => 'pos', 'alt' => 'The SGMS point of sale with items in the cart',
         'text' => 'Scan a barcode or tap a product, take cash, bank transfer, wallet or credit, and print a thermal receipt. Returns and exchanges go back into stock on their own.'],
        ['id' => 'books', 'title' => 'Keep the books', 'shot' => 'ledger', 'alt' => 'A customer ledger with sales and payments in SGMS',
         'text' => 'Every sale, payment and return lands in the customer, vendor and cash drawer ledgers. Send a customer their statement as a PDF.'],
        ['id' => 'numbers', 'title' => 'Read the numbers', 'shot' => 'reports', 'alt' => 'Profit and loss report in SGMS',
         'text' => 'Profit and loss, sales by product, size, branch or channel, and the value of your stock on any date.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SGMS | Inventory, POS and online store for garment shops</title>
    <meta name="description" content="SGMS runs garment and apparel shops: lot purchases by colour and size, stock across branches, a fast POS, ledgers, reports and an online store on the same stock.">
    <meta property="og:title" content="SGMS | Garment shop software">
    <meta property="og:description" content="Lot purchases, branch stock, POS, ledgers and an online store for garment shops. Try the demo in one click.">
    <meta property="og:image" content="{{ $shot('pos') }}">
    <meta name="theme-color" content="#1d2a48">
    <link rel="icon" href="{{ asset('landing/tag.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800&family=Instrument+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('landing/landing.css') }}?v={{ filemtime(public_path('landing/landing.css')) }}">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="nav">
    <div class="wrap nav__bar">
        <a href="{{ route('landing') }}" class="brand" aria-label="SGMS home">
            <img src="{{ asset('landing/tag.svg') }}" alt="" width="28" height="28">
            <span>SGMS</span>
        </a>
        <nav class="nav__links" aria-label="Sections">
            <a href="#tour">How it works</a>
            <a href="#store">Online store</a>
            <a href="#plans">Plans</a>
        </nav>
        <div class="nav__actions">
            @if (auth('web')->check())
                <a class="btn btn--line" href="{{ route('app.dashboard') }}">Open my dashboard</a>
            @else
                <a class="nav__signin" href="{{ route('app.login') }}">Sign in</a>
                @if ($demo)<a class="btn btn--thread" href="#demo">Try the demo</a>@endif
            @endif
        </div>
    </div>
</header>

<main id="main">
    {{-- Hero: the clothing rail. Each hang tag signs you in as one person in the demo shop. --}}
    <section class="hero" aria-labelledby="hero-title">
        <div class="wrap hero__grid">
            <div>
                <h1 id="hero-title" class="hero__title">From the lot you buy to the last size you sell.</h1>
                <p class="hero__lede">SGMS runs garment and apparel shops: lot purchases by colour and size, stock across branches, a fast POS, customer and vendor ledgers, and an online store that sells from the same stock.</p>
            </div>
            @if ($demo)
                <div class="rack__head" id="demo">
                    <h2 id="demo-title">Pick a tag to open the demo shop</h2>
                    <p>No sign-up. You're signed in to Urban Garments, a demo clothing shop with two branches. Changes are cleared every night at {{ $resetTime }}.</p>
                    @if (session('demo_error'))
                        <p class="rack__error" role="alert">{{ session('demo_error') }}</p>
                    @endif
                </div>
            @endif
        </div>

        @if ($demo)
            <div class="rack" role="group" aria-labelledby="demo-title">
                <div class="rack__rail" aria-hidden="true"></div>
                <ul class="wrap rack__tags" role="list">
                    @foreach ($portals as $key => $p)
                        <li class="tag" style="--i: {{ $loop->index }}">
                            <form method="POST" action="{{ route('demo.enter', $key) }}">
                                @csrf
                                <button type="submit" class="tag__card">
                                    <span class="tag__hole" aria-hidden="true"></span>
                                    <span class="tag__role">{{ $p['role'] }}</span>
                                    <span class="tag__who">{{ $p['who'] }}</span>
                                    <span class="tag__sees">{{ $p['sees'] }}</span>
                                    <span class="tag__go">{{ $p['button'] }}</span>
                                    <span class="tag__code" aria-hidden="true"></span>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                @if ($demoStoreUrl)
                    <p class="wrap rack__store">Or <a href="{{ $demoStoreUrl }}">browse the demo shop's online store</a> as a shopper, no sign-in needed.</p>
                @endif
            </div>
        @else
            <div class="wrap hero__cta">
                <a class="btn btn--thread btn--lg" href="{{ $whatsapp }}" target="_blank" rel="noopener">Ask for a demo on WhatsApp</a>
            </div>
        @endif
    </section>

    {{-- Tour: the path goods take through the shop, so the stations are numbered. --}}
    <section class="tour" id="tour" aria-labelledby="tour-title">
        <div class="wrap">
            <header class="section-head">
                <h2 id="tour-title">How stock moves through SGMS</h2>
                <p>One record of every piece, from the vendor's lot to the customer's bag. Each step updates the next, so stock, ledgers and reports always agree.</p>
            </header>

            <ol class="seam" role="list">
                @foreach ($tour as $step)
                    <li class="station" id="{{ $step['id'] }}">
                        <div class="station__text">
                            <span class="station__num" aria-hidden="true">{{ $loop->iteration }}</span>
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['text'] }}</p>
                        </div>
                        <figure class="shot">
                            <button type="button" class="shot__open" data-zoom="{{ $shot($step['shot']) }}" data-alt="{{ $step['alt'] }}" aria-label="Enlarge: {{ $step['alt'] }}">
                                <img src="{{ $shot($step['shot']) }}" alt="{{ $step['alt'] }}" width="1440" height="900" loading="lazy" decoding="async">
                            </button>
                        </figure>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Online store --}}
    <section class="store" id="store" aria-labelledby="store-title">
        <div class="wrap store__grid">
            <div class="store__text">
                <h2 id="store-title">Your stock, open online</h2>
                <p>Every shop gets its own store at its own address, selling from the same stock as the counter. Orders wait for you to confirm, then leave from the branch you pick.</p>
                <ul class="ticks" role="list">
                    <li>A photo for every colour and a size chart for every product</li>
                    <li>Cash on delivery, bank transfer and JazzCash or Easypaisa</li>
                    <li>A delivery charge, with free delivery above an amount you set</li>
                    <li>Coupons, wishlists, reviews and order tracking</li>
                </ul>
                @if ($demoStoreUrl)
                    <a class="btn btn--thread" href="{{ $demoStoreUrl }}">Visit the demo store</a>
                @endif
            </div>
            <figure class="shot shot--dark">
                <button type="button" class="shot__open" data-zoom="{{ $shot('orders') }}" data-alt="An online order in SGMS, with stock per branch and payment details" aria-label="Enlarge: an online order in SGMS">
                    <img src="{{ $shot('orders') }}" alt="An online order in SGMS, with stock per branch and payment details" width="1440" height="900" loading="lazy" decoding="async">
                </button>
            </figure>
        </div>

        <div class="wrap themes">
            <h3>Five store designs, switched in one click</h3>
            <ul class="themes__row" role="list">
                @foreach ($themes as $key => $theme)
                    <li class="theme">
                        <button type="button" class="shot__open" data-zoom="{{ $shot('theme-'.$key) }}" data-alt="The {{ $theme['name'] }} store theme" aria-label="Enlarge: the {{ $theme['name'] }} store theme">
                            <img src="{{ $shot('theme-'.$key) }}" alt="The {{ $theme['name'] }} store theme" width="1280" height="800" loading="lazy" decoding="async">
                        </button>
                        <b>{{ $theme['name'] }}</b>
                        <span>{{ $theme['best_for'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Plans --}}
    <section class="plans" id="plans" aria-labelledby="plans-title">
        <div class="wrap">
            <header class="section-head">
                <h2 id="plans-title">Plans</h2>
                <p>Every plan includes the POS, purchases, stock and ledgers. Message us with your shop's size and we'll send a price.</p>
            </header>
            <div class="plans__grid">
                @foreach ($plans as $key => $plan)
                    <article class="plan @if ($key === 'standard') plan--pick @endif">
                        <h3>{{ $plan['name'] }}</h3>
                        <p class="plan__for">{{ $plan['for'] }}</p>
                        <ul class="ticks" role="list">
                            @foreach ($plan['features'] as $feature)
                                <li>{{ $feature }}</li>
                            @endforeach
                        </ul>
                        <a class="btn {{ $key === 'standard' ? 'btn--thread' : 'btn--line' }}" href="{{ $plan['ask'] }}" target="_blank" rel="noopener">Ask for a price</a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="close" aria-labelledby="close-title">
        <div class="wrap close__inner">
            <h2 id="close-title">Want to see it with your own stock?</h2>
            <p>Send us a message on WhatsApp. We'll walk you through it and set up your shop.</p>
            <a class="btn btn--thread btn--lg" href="{{ $whatsapp }}" target="_blank" rel="noopener">Message us on WhatsApp</a>
        </div>
    </section>
</main>

<footer class="foot">
    <div class="wrap foot__inner">
        <a href="{{ route('landing') }}" class="brand">
            <img src="{{ asset('landing/tag.svg') }}" alt="" width="24" height="24">
            <span>SGMS</span>
        </a>
        <p>Inventory, POS and online store software for garment and apparel shops.</p>
        <p class="foot__links"><a href="{{ route('app.login') }}">Staff sign in</a></p>
    </div>
</footer>

{{-- WhatsApp: fixed bottom right, buzzes to draw attention. --}}
<a class="wa" href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp">
    <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true"><path fill="currentColor" d="M16.04 3C8.86 3 3.03 8.82 3.03 16c0 2.3.6 4.53 1.74 6.5L3 29l6.68-1.75A12.95 12.95 0 0 0 16.04 29C23.2 29 29 23.18 29 16S23.2 3 16.04 3Zm0 23.62c-2 0-3.96-.54-5.67-1.56l-.4-.24-3.97 1.04 1.06-3.86-.26-.4A10.6 10.6 0 0 1 5.4 16c0-5.86 4.77-10.63 10.64-10.63 5.86 0 10.6 4.77 10.6 10.63 0 5.87-4.74 10.62-10.6 10.62Zm5.83-7.95c-.32-.16-1.89-.93-2.18-1.04-.3-.1-.5-.16-.72.16-.21.32-.82 1.04-1 1.25-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.57-1.59a9.62 9.62 0 0 1-1.78-2.2c-.19-.32-.02-.5.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.1-.21.05-.4-.03-.56-.08-.16-.72-1.73-.98-2.37-.26-.62-.52-.54-.72-.55h-.61c-.21 0-.56.08-.85.4-.29.32-1.11 1.09-1.11 2.65 0 1.57 1.14 3.08 1.3 3.3.16.2 2.24 3.43 5.43 4.8.76.33 1.35.53 1.81.67.76.24 1.45.21 2 .13.61-.09 1.89-.77 2.15-1.52.27-.74.27-1.38.19-1.51-.08-.13-.29-.21-.61-.37Z"/></svg>
</a>

<dialog class="zoom" id="zoom" aria-label="Screenshot">
    <form method="dialog"><button class="zoom__close" aria-label="Close">&times;</button></form>
    <img alt="">
</dialog>

<script>
    // Screenshot lightbox (native <dialog>; Esc and the close button work without extra code).
    (() => {
        const dialog = document.getElementById('zoom');
        const img = dialog.querySelector('img');
        document.addEventListener('click', (e) => {
            const open = e.target.closest('[data-zoom]');
            if (open) {
                img.src = open.dataset.zoom;
                img.alt = open.dataset.alt || '';
                dialog.showModal();
            } else if (e.target === dialog) {
                dialog.close();
            }
        });
    })();
</script>
</body>
</html>
