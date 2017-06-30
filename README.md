
# Otrs (github.com/OTRS) To Elastic/Kibana export

![](https://github.com/gitfrage/otrs-to-elastic/blob/master/otrs.jpg?raw=true)

## Use Cases: Key Performance Indicator (KPI) Tracker 

Tracked Events: "EmailCustomer", "SendAnswer", "FollowUp"

1. Standard Evens History: EmailCustomer -> SendAnswer -> FollowUp -> SendAnswer -> ...

2. also possible Evens History: SendAnswer -> SendAnswer -> FollowUp -> FollowUp -> ...

## Install
 
```
# start elastic and grafana:
/Downloads/elasticsearch-5.4.1$ bin/elasticsearch
/Downloads/grafana-4.3.2$ bin/grafana-server

# make ssh tunel to mysql like
ssh -v -L 3366:localhost:3306 use@server

# import grafana.json
```

## OTRS Ticket Domain Model

https://github.com/OTRS/otrs/blob/master/development/diagrams/Database/OTRSDatabaseDiagram.png
