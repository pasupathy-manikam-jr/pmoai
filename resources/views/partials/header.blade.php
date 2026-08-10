<header class="pm-header">
    <a class="pm-brand" href="{{ route('snapshots.index') }}">PMFAI</a>
    <nav>
        <a href="{{ route('snapshots.index') }}"
           class="{{ request()->routeIs('snapshots.index') ? 'active' : '' }}">Analyses</a>
        <a href="{{ route('simulator') }}"
           class="{{ request()->routeIs('simulator') ? 'active' : '' }}">Simulator</a>
        <a href="{{ route('rebalance') }}"
           class="{{ request()->routeIs('rebalance') ? 'active' : '' }}">Rebalance</a>
        <a href="{{ route('advisor') }}"
           class="{{ request()->routeIs('advisor') ? 'active' : '' }}">Advisor</a>
        <a href="{{ route('snapshots.create') }}"
           class="{{ request()->routeIs('snapshots.create') ? 'active' : '' }}">New analysis</a>
        <a href="{{ route('dashboard') }}"
           class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('glossary') }}"
           class="{{ request()->routeIs('glossary') ? 'active' : '' }}">Glossary</a>
    </nav>
</header>
