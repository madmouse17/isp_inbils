# Warehouse business rules

Warehouse manages physical stock locations, transfers, and receiving/issuing coordination. Stock quantity changes only through immutable Inventory stock movement records. Transfers need source, destination, item, quantity, actor, timestamp, and reference. Corrections use auditable adjustment movement, not row mutation.
