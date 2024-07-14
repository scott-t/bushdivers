import {
  Box,
  Card,
  CardBody,
  Grid,
  GridItem,
  Image,
  Select,
  Stat,
  StatHelpText,
  StatLabel,
  StatNumber,
} from '@chakra-ui/react'
import { router } from '@inertiajs/react'
import React from 'react'

import DashTableCard from '../../components/admin/DashTableCard.jsx'
import AdminLayout from '../../components/layout/AdminLayout.jsx'

const Dashboard = ({ days, stats, pilots, airports, aircraft }) => {
  function onSetDays(e) {
    router.get('', { days: e.target.value })
  }

  return (
    <>
      {}
      <Grid templateColumns="repeat(4, 1fr)" gap={6}>
        <GridItem colSpan={4}>
          Date range:
          <Box display={'inline-block'}>
            <Select marginLeft="1em" defaultValue={days} onChange={onSetDays}>
              <option value="7">7 days</option>
              <option value="14">14 days</option>
              <option value="21">21 days</option>
              <option value="30">30 days</option>
              <option value="60">60 days</option>
              <option value="90">90 days</option>
            </Select>
          </Box>
        </GridItem>
        <GridItem colSpan={[2, null, null, null, 1]}>
          <Card>
            <CardBody>
              <Stat>
                <StatLabel>Flights</StatLabel>
                <StatNumber>{stats.flights}</StatNumber>
                <StatHelpText>Total flights</StatHelpText>
              </Stat>
            </CardBody>
          </Card>
        </GridItem>
        <GridItem colSpan={[2, null, null, null, 1]}>
          <Card>
            <CardBody>
              <Stat>
                <StatLabel>Airports</StatLabel>
                <StatNumber>{stats.airports}</StatNumber>
                <StatHelpText>Total unique airports</StatHelpText>
              </Stat>
            </CardBody>
          </Card>
        </GridItem>
        <GridItem colSpan={[2, null, null, null, 1]}>
          <Card>
            <CardBody>
              <Stat>
                <StatLabel>Pilots</StatLabel>
                <StatNumber>{stats.pilots}</StatNumber>
                <StatHelpText>Total unique pilots</StatHelpText>
              </Stat>
            </CardBody>
          </Card>
        </GridItem>
        <GridItem colSpan={[2, null, null, null, 1]}>
          <Card>
            <CardBody>
              <Stat>
                <StatLabel>Aircraft</StatLabel>
                <StatNumber>{stats.aircraft}</StatNumber>
                <StatHelpText>
                  Unique aircraft ({stats.fleet} fleet)
                </StatHelpText>
              </Stat>
            </CardBody>
          </Card>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Pilots by Flights"
            itemName="Pilot"
            unit="Flights"
            data={pilots.flights}
          >
            {(item) => <>BDV{item.id.toString().padStart(4, '0')}</>}
          </DashTableCard>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Pilots by Hours"
            itemName="Pilot"
            unit="Hours"
            data={pilots.hours}
          >
            {(item) => <>BDV{item.id.toString().padStart(4, '0')}</>}
          </DashTableCard>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Departures"
            itemName="Airport"
            unit="Flights"
            data={airports.departures}
          >
            {(item) => (
              <>
                {item.id}
                {!!item.flag && <Image rounded="sm" h={5} src={item.flag} />}
              </>
            )}
          </DashTableCard>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Arrivals"
            itemName="Airport"
            unit="Flights"
            data={airports.arrivals}
          >
            {(item) => (
              <>
                {item.id}
                {!!item.flag && <Image rounded="sm" h={5} src={item.flag} />}
              </>
            )}
          </DashTableCard>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Airframes"
            itemName="Fleet Type"
            data={aircraft.types}
          >
            {(item) => (
              <>
                {item.type} {item.name}
              </>
            )}
          </DashTableCard>
        </GridItem>
        <GridItem colSpan={[4, null, null, null, 2]}>
          <DashTableCard
            title="Top Fleet Aircraft"
            itemName="Registration"
            data={aircraft.fleet}
          >
            {(ac) => (
              <>
                {ac.registration} {ac.type} {ac.name}
              </>
            )}
          </DashTableCard>
        </GridItem>
      </Grid>
    </>
  )
}

Dashboard.layout = (page) => (
  <AdminLayout children={page} title="Admin - Dashboard" heading="Dashboard" />
)

export default Dashboard
