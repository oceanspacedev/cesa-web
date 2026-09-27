<div class="cesa-auth-shell">
    <section class="cesa-auth-panel">
        <div class="cesa-auth-panel__inner">
            <span class="cesa-auth-panel__brand-text">CESA</span>

            <div class="cesa-auth-panel__card">
                <div class="cesa-auth-panel__header">
                    @if (filled($this->getHeading()))
                        <h1 class="cesa-auth-panel__title">
                            {{ $this->getHeading() }}
                        </h1>
                    @endif

                    @if (filled($this->getSubheading()))
                        <p class="cesa-auth-panel__subtitle">
                            {{ $this->getSubheading() }}
                        </p>
                    @endif
                </div>

                {{ $this->content }}

                @php
                    $whatsappLoginVisible = blank($this->userUndertakingMultiFactorAuthentication)
                        && app(\App\Services\WhatsApp\WagHubClient::class)->isHubConfigured();
                @endphp

                @if ($whatsappLoginVisible)
                    <div class="cesa-auth-whatsapp">
                        <div class="cesa-auth-whatsapp__divider">
                            <span class="cesa-auth-whatsapp__divider-label">Atau masuk dengan</span>
                        </div>

                        <a
                            href="{{ route('filament.admin.phone-login') }}"
                            class="cesa-auth-whatsapp__button"
                        >
                            <svg
                                class="cesa-auth-whatsapp__icon"
                                viewBox="0 0 16 16"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.043 3.963L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.78-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.99-1.174-1.105-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.906.13 1.25.08.381-.058 1.171-.48 1.338-.941.164-.464.164-.86.114-.941-.049-.084-.182-.133-.38-.232" />
                            </svg>
                            WhatsApp
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <aside
        class="cesa-auth-media"
        aria-hidden="true"
    ></aside>
</div>
