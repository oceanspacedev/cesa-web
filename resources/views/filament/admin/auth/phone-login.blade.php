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
                        <div class="cesa-auth-panel__whatsapp-subheading">
                            {{ $this->getSubheading() }}
                        </div>
                    @endif
                </div>

                {{ $this->content }}
            </div>
        </div>
    </section>

    <aside
        class="cesa-auth-media"
        aria-hidden="true"
    ></aside>
</div>
