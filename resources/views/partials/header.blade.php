<header class="pm-header">
    <a class="pm-brand" href="{{ route('snapshots.index') }}">pmoai</a>
    <nav>
        <a href="{{ route('snapshots.index') }}"
           class="{{ request()->routeIs('snapshots.index') ? 'active' : '' }}">Analyses</a>
        <a href="{{ route('simulator') }}"
           class="{{ request()->routeIs('simulator') ? 'active' : '' }}">Simulator</a>
        <a href="{{ route('snapshots.create') }}"
           class="{{ request()->routeIs('snapshots.create') ? 'active' : '' }}">New analysis</a>
        <a href="{{ route('dashboard') }}"
           class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
    </nav>
</header>
